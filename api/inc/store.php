<?php
/**
 * LOTAL GHANA — session cart and order placement.
 * The cart lives in the database-backed session row, so it survives across
 * serverless invocations (no local files, no Redis needed).
 */

declare(strict_types=1);

/** @return array<string,int> product id => qty */
function lotal_cart(): array
{
    $session = lotal_session();
    $cart = $session['cart'] ?? [];
    if (!is_array($cart)) {
        return [];
    }
    $clean = [];
    foreach ($cart as $id => $qty) {
        $qty = max(0, min(5, (int) $qty));
        if ($qty > 0 && is_string($id)) {
            $clean[$id] = $qty;
        }
    }
    return $clean;
}

function lotal_cart_save(array $cart): void
{
    lotal_session_put(['cart' => $cart]);
}

function lotal_cart_add(string $productId, int $qty = 1): array
{
    $product = lotal_product_by_id($productId);
    if (!$product) {
        return ['ok' => false, 'error' => 'That unit is no longer listed.'];
    }
    if ((int) $product['stock'] < 1) {
        return ['ok' => false, 'error' => 'That unit has just sold out.'];
    }

    $cart = lotal_cart();
    $cart[$productId] = min(5, ($cart[$productId] ?? 0) + max(1, $qty));
    lotal_cart_save($cart);

    return ['ok' => true, 'count' => lotal_cart_count()];
}

function lotal_cart_set(string $productId, int $qty): array
{
    $cart = lotal_cart();
    if ($qty <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = min(5, $qty);
    }
    lotal_cart_save($cart);

    return ['ok' => true, 'count' => lotal_cart_count()];
}

function lotal_cart_clear(): void
{
    lotal_cart_save([]);
}

function lotal_cart_count(): int
{
    return array_sum(lotal_cart());
}

/** @return array<int,array{product:array,qty:int,line_total:int}> */
function lotal_cart_lines(): array
{
    $lines = [];
    foreach (lotal_cart() as $productId => $qty) {
        $product = lotal_product_by_id($productId);
        if (!$product) {
            continue;
        }
        $lines[] = [
            'product'    => $product,
            'qty'        => $qty,
            'line_total' => $product['price'] * $qty,
        ];
    }
    return $lines;
}

/** @return array{subtotal:int,delivery:int,total:int,free:bool} */
function lotal_cart_totals(string $city): array
{
    $subtotal = 0;
    foreach (lotal_cart_lines() as $line) {
        $subtotal += $line['line_total'];
    }
    $fee = lotal_delivery_fee($city);
    if ($subtotal >= lotal_config()['delivery_free_over'] && in_array($city, ['Accra', 'Tema'], true)) {
        $fee = 0;
    }
    return ['subtotal' => $subtotal, 'delivery' => $fee, 'total' => $subtotal + $fee, 'free' => $fee === 0];
}

/* ------------------------------------------------------------------ */
/* placing an order                                                    */
/* ------------------------------------------------------------------ */

function lotal_unique_code(): string
{
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $code = lotal_new_tracking_code();
        $clash = lotal_one('SELECT id FROM orders WHERE code = ?', [$code]);
        if (!$clash) {
            return $code;
        }
    }
    throw new RuntimeException('Could not allocate a unique tracking code. Please try again.');
}

/**
 * Creates the order, its line items and the first tracking event in one
 * transaction, then reserves stock. Returns the tracking code so the caller
 * can send the customer straight to payment.
 *
 * @param array<string,string> $input
 */
function lotal_place_order(array $input): array
{
    $user = lotal_current_user();
    if ($user === null) {
        return ['ok' => false, 'error' => 'Please sign in before checking out.', 'code' => 'AUTH_REQUIRED'];
    }

    $lines = lotal_cart_lines();
    if (!$lines) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }

    $fullName = trim((string) ($input['fullName'] ?? $user['full_name']));
    $phone    = trim((string) ($input['phone'] ?? $user['phone']));
    $email    = strtolower(trim((string) ($input['email'] ?? $user['email'])));
    $city     = trim((string) ($input['city'] ?? $user['city'] ?: 'Accra'));
    $address  = trim((string) ($input['address'] ?? ''));
    $notes    = trim((string) ($input['notes'] ?? ''));
    $method   = in_array((string) ($input['payment_method'] ?? ''), array_keys(lotal_payment_labels()), true)
        ? (string) $input['payment_method']
        : 'paystack';

    if ($fullName === '' || $phone === '' || mb_strlen($address) < 8) {
        return ['ok' => false, 'error' => 'Please complete the delivery details before placing your order.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please provide a valid email address for your receipt.'];
    }
    foreach ($lines as $line) {
        if ((int) $line['product']['stock'] < $line['qty']) {
            return ['ok' => false, 'error' => $line['product']['name'] . ' just sold out. Please adjust your cart.'];
        }
    }

    $totals = lotal_cart_totals($city);
    $code = lotal_unique_code();

    $pdo = lotal_pdo();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT INTO orders (code, user_id, status, subtotal, delivery, total, payment_method,
                                 payment_reference, full_name, phone, email, city, address, notes,
                                 created_at, updated_at)
             VALUES (?, ?, 'placed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
             RETURNING id"
        );
        $insert->execute([
            $code, $user['id'], $totals['subtotal'], $totals['delivery'], $totals['total'], $method,
            'PENDING-' . lotal_reference_for($code), $fullName, $phone, $email, $city, $address, $notes,
        ]);
        // RETURNING keeps this correct on a pooler where lastInsertId() is unreliable.
        $orderId = (string) ($insert->fetchColumn() ?: '');
        $insert->closeCursor();
        if ($orderId === '') {
            $row = lotal_one('SELECT id FROM orders WHERE code = ?', [$code]);
            $orderId = (string) ($row['id'] ?? '');
        }

        $item = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, title, brand, image, unit_price, qty)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stockDown = $pdo->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?');
        foreach ($lines as $line) {
            $product = $line['product'];
            $item->execute([
                $orderId,
                $product['id'],
                $product['brand'] . ' ' . $product['name'],
                $product['brand'],
                $product['image'],
                $product['price'],
                $line['qty'],
            ]);
            $stockDown->execute([$line['qty'], $product['id']]);
        }

        lotal_exec('INSERT INTO order_events (order_id, status, title, note) VALUES (?, ?, ?, ?)', [
            $orderId,
            'placed',
            lotal_status_label('placed'),
            lotal_statuses()['placed']['note'] . ' Code ' . $code . '.',
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    lotal_cart_clear();

    return [
        'ok'            => true,
        'code'          => $code,
        'total'         => $totals['total'],
        'needs_payment' => lotal_needs_payment($method),
    ];
}
