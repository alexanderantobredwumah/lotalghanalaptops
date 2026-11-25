<?php
/**
 * LOTAL GHANA — PHP application front controller.
 *
 * One entry point for every route (pages, forms and JSON endpoints), which is
 * what makes this deployable as a single Vercel function. See php/README.md.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/store.php';
require __DIR__ . '/inc/paystack.php';
require __DIR__ . '/inc/pages.php';
require __DIR__ . '/inc/storefront.php';
require __DIR__ . '/inc/pages-cart.php';
require __DIR__ . '/inc/admin.php';

/* ------------------------------------------------------------------ */
/* response hardening                                                  */
/* ------------------------------------------------------------------ */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$route = lotal_route();

// Paystack posts server-to-server and authenticates with an SHA-512 HMAC
// signature instead of a CSRF token — every other POST is form-guarded.
if (!in_array($route, ['/paystack/webhook', '/api/paystack/webhook'], true)) {
    lotal_csrf_verify();
}
$action = strtolower(lotal_post('action', lotal_get('action')));

try {
    lotal_dispatch($route, $action);
} catch (Throwable $exception) {
    if (lotal_env('APP_DEBUG', '0') === '1') {
        throw $exception;
    }
    if (str_starts_with($route, '/api') || str_contains($route, 'paystack')) {
        lotal_json(['ok' => false, 'error' => 'Server error: ' . $exception->getMessage()], 500);
    }
    http_response_code(500);
    $message = lotal_config()['db']['url'] === ''
        ? 'Database not configured. Add DATABASE_URL to <code>php/.env</code> (Supabase → Project Settings → Database → Connection string), '
        . 'then open <code>/install</code> to create the tables.'
        : 'Something went wrong on our side: ' . lotal_e($exception->getMessage());
    echo lotal_layout('Error', '<section class="wrap slim"><div class="plate-flat empty"><p class="display">We hit a problem.</p>'
        . '<p class="muted">' . $message . '</p>'
        . '<a class="btn btn-primary" href="' . lotal_url('/') . '">Back to the store</a></div></section>');
}

/* ------------------------------------------------------------------ */
/* router                                                             */
/* ------------------------------------------------------------------ */

function lotal_dispatch(string $route, string $action): never
{
    /* ---------- JSON / gateway endpoints ---------- */
    if ($route === '/health') {
        lotal_json(['ok' => true, 'service' => 'lotal-ghana-php', 'time' => date('c')]);
    }

    if ($route === '/paystack/webhook' || $route === '/api/paystack/webhook') {
        lotal_handle_webhook();
    }

    if ($route === '/paystack/start' && lotal_is_post()) {
        lotal_handle_paystack_start();
    }

    if ($route === '/paystack/confirm' && lotal_is_post()) {
        lotal_handle_paystack_confirm();
    }

    if ($route === '/paystack/callback') {
        $reference = lotal_get('reference');
        $result = lotal_paystack_enabled() ? lotal_paystack_confirm($reference) : ['ok' => false, 'code' => lotal_code_for_reference($reference)];
        $code = (string) ($result['code'] ?? lotal_code_for_reference($reference));
        lotal_redirect('/order/' . rawurlencode($code) . (($result['ok'] ?? false) ? '?paid=1' : ''));
    }

    /* ---------- install / migrate ---------- */
    if ($route === '/install') {
        lotal_handle_install();
    }

    /* ---------- POST form actions ---------- */
    if (lotal_is_post() && $action !== '') {
        lotal_handle_post($route, $action);
    }

    /* ---------- pages ---------- */
    $user = lotal_current_user();

    switch (true) {
        case $route === '/' || $route === '/index':
            $products = lotal_products(['sort' => 'featured']);
            echo lotal_page_home($products, lotal_brands(), lotal_testimonials());
            exit;

        case $route === '/shop':
            echo lotal_page_shop(
                lotal_products([
                    'brand' => lotal_get('brand', 'All'),
                    'q'     => lotal_get('q'),
                    'sort'  => lotal_get('sort', 'featured'),
                ]),
                lotal_brands(),
                lotal_get('brand') === 'All' ? '' : lotal_get('brand'),
                lotal_get('q'),
                lotal_get('sort', 'featured')
            );
            exit;

        case str_starts_with($route, '/product/'):
            $product = lotal_product(rawurldecode(substr($route, strlen('/product/'))));
            if ($product === null) {
                http_response_code(404);
                echo lotal_page_404();
                exit;
            }
            echo lotal_page_product(
                $product,
                lotal_related((string) $product['brand'], (string) $product['id'])
            );
            exit;

        case $route === '/cart':
            echo lotal_page_cart();
            exit;

        case $route === '/checkout':
            echo lotal_page_checkout(lotal_require_user());
            exit;

        case str_starts_with($route, '/pay/'):
            $order = lotal_order_by_code(substr($route, strlen('/pay/')));
            if ($order === null) {
                lotal_redirect('/shop');
            }
            if ($user && $order['user_id'] && $user['id'] !== $order['user_id']) {
                lotal_redirect('/account');
            }
            if (!$user) {
                lotal_redirect('/login?next=' . rawurlencode($route));
            }
            echo lotal_page_pay($order, lotal_order_items((string) $order['id']));
            exit;

        case str_starts_with($route, '/order/'):
            $order = lotal_order_by_code(substr($route, strlen('/order/')));
            if ($order === null) {
                http_response_code(404);
                echo lotal_page_404();
                exit;
            }
            echo lotal_page_order($order, lotal_get('paid') === '1');
            exit;

        case $route === '/track':
            $requested = lotal_get('code');
            $error = null;
            $order = null;
            if ($requested !== '') {
                if (!lotal_valid_code($requested)) {
                    $error = 'That is not a valid tracking code. Check the code on your receipt — it looks like LT-4KZP-92QF-7M.';
                } else {
                    $order = lotal_order_by_code($requested);
                    $error = $order === null ? 'No order was found for that tracking code.' : null;
                }
            }
            echo lotal_page_track($order, $requested, $error);
            exit;

        case $route === '/account':
            echo lotal_page_account(lotal_require_user(), lotal_orders_for_user((string) $user['id']));
            exit;

        case in_array($route, ['/login', '/register', '/forgot-password', '/reset-password'], true):
            $mode = match ($route) {
                '/login' => 'login',
                '/register' => 'register',
                '/forgot-password' => 'forgot',
                default => 'reset',
            };
            echo lotal_page_auth($mode, lotal_get('next', '/account'), lotal_get('token'));
            exit;

        case $route === '/admin' || $route === '/admin.html' || $route === '/admin.php':
            lotal_handle_admin($route);
            exit;

        default:
            http_response_code(404);
            echo lotal_page_404();
            exit;
    }
}

/* ------------------------------------------------------------------ */
/* POST handlers                                                       */
/* ------------------------------------------------------------------ */

function lotal_handle_post(string $route, string $action): never
{
    switch ($action) {
        case 'cart_add':
        case 'cart_set':
        case 'cart_inc':
        case 'cart_dec':
        case 'cart_clear': {
            $productId = lotal_post('product_id');
            $qty = (int) lotal_post('qty', '1');

            if ($action === 'cart_clear') {
                lotal_cart_clear();
                lotal_flash('Cart cleared.');
                lotal_redirect('/cart');
            }
            if ($action === 'cart_inc') {
                lotal_cart_add($productId, 1);
                lotal_flash('Unit added to your cart.');
                lotal_redirect($route === '/cart' ? '/cart' : '/product/' . $productId);
            }
            if ($action === 'cart_dec') {
                $current = lotal_cart()[$productId] ?? 1;
                lotal_cart_set($productId, max(0, $current - 1));
                lotal_redirect('/cart');
            }

            $result = $action === 'cart_add' ? lotal_cart_add($productId, $qty) : lotal_cart_set($productId, $qty);
            if (!$result['ok']) {
                lotal_flash((string) $result['error'], 'err');
            } else {
                lotal_flash($action === 'cart_add' ? 'Unit added to your cart.' : 'Cart updated.');
            }
            lotal_redirect($route === '/cart' ? '/cart' : '/product/' . $productId);
        }

        case 'place_order': {
            $result = lotal_place_order([
                'fullName'       => lotal_post('fullName'),
                'phone'          => lotal_post('phone'),
                'email'          => lotal_post('email'),
                'city'           => lotal_post('city'),
                'address'        => lotal_post('address'),
                'notes'          => lotal_post('notes'),
                'payment_method' => lotal_post('payment_method', 'paystack'),
            ]);
            if (!$result['ok']) {
                lotal_flash((string) $result['error'], 'err');
                lotal_redirect('/checkout');
            }
            $code = (string) $result['code'];
            lotal_flash('Order placed. Your tracking code is ' . $code . '.', 'ok');
            lotal_redirect(!empty($result['needs_payment']) ? '/pay/' . $code : '/order/' . $code);
        }

        case 'register': {
            $result = lotal_register($_POST);
            if (!$result['ok']) {
                lotal_flash((string) $result['error'], 'err');
                lotal_redirect('/register');
            }
            lotal_flash('Welcome to LOTAL GHANA, ' . $result['user']['full_name'] . '. Your account is ready.');
            lotal_redirect(lotal_post('next', '/account'));
        }

        case 'login': {
            $result = lotal_login(lotal_post('email'), lotal_post('password'));
            if (!$result['ok']) {
                lotal_flash((string) $result['error'], 'err');
                lotal_redirect('/login');
            }
            if ($result['admin']) {
                lotal_redirect('/admin');
            }
            lotal_flash('Signed in. Welcome back.');
            lotal_redirect(lotal_post('next', '/account'));
        }

        case 'forgot': {
            $email = strtolower(lotal_post('email'));
            $user = lotal_find_user($email);
            $link = null;
            if ($user) {
                $token = lotal_issue_reset((string) $user['id']);
                $link = lotal_url('/reset-password?token=' . $token);
                // In production this is emailed (Supabase Auth / SMTP). Logged for the operator.
                error_log('[LOTAL] password reset for ' . $email . ' -> ' . $link);
            }
            lotal_flash(
                $link
                    ? 'A reset link has been issued. In production it is emailed to you; in this build it is ' . $link
                    : 'If that email is registered, a reset link has been issued.'
            );
            lotal_redirect('/forgot-password');
        }

        case 'reset': {
            $result = lotal_consume_reset(lotal_post('token'), lotal_post('password'));
            if (!$result['ok']) {
                lotal_flash((string) $result['error'], 'err');
                lotal_redirect('/reset-password?token=' . rawurlencode(lotal_post('token')));
            }
            lotal_flash('Password updated. You can now sign in.');
            lotal_redirect('/login');
        }

        case 'logout':
            lotal_logout();
            lotal_flash('Signed out.');
            lotal_redirect('/');
    }

    // Unknown action on a page route: return to where the visitor came from.
    lotal_redirect($route === '/' ? '/' : $route);
}

/* ------------------------------------------------------------------ */
/* paystack                                                            */
/* ------------------------------------------------------------------ */

function lotal_handle_paystack_start(): never
{
    if (!lotal_paystack_enabled()) {
        lotal_json([
            'ok'    => false,
            'error' => 'Paystack is not configured. Add PAYSTACK_SECRET_KEY and PAYSTACK_PUBLIC_KEY to php/.env, then restart PHP.',
        ], 400);
    }

    $code = lotal_normalise_code(lotal_post('code', lotal_get('code')));
    $order = lotal_order_by_code($code);
    if (!$order) {
        lotal_json(['ok' => false, 'error' => 'Order not found.'], 404);
    }

    $user = lotal_current_user();
    if ($order['user_id'] && (!$user || $user['id'] !== $order['user_id'])) {
        lotal_json(['ok' => false, 'error' => 'You cannot pay for this order.'], 403);
    }
    if ($order['status'] !== 'placed') {
        lotal_json(['ok' => false, 'error' => 'This order has already been paid.'], 409);
    }

    $result = lotal_paystack_initialize($order);
    lotal_json($result, $result['ok'] ? 200 : 502);
}

function lotal_handle_paystack_confirm(): never
{
    if (!lotal_paystack_enabled()) {
        lotal_json(['ok' => false, 'error' => 'Paystack is not configured.'], 400);
    }
    $result = lotal_paystack_confirm(lotal_post('reference', lotal_get('reference')));
    if (!$result['ok'] && str_contains((string) ($result['error'] ?? ''), 'unreachable')) {
        lotal_json($result, 502);
    }
    lotal_json($result, $result['ok'] ? 200 : 402);
}

function lotal_handle_webhook(): never
{
    $raw = (string) file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? $_SERVER['HTTP_X-PAYSTACK-SIGNATURE'] ?? null;

    if (!lotal_paystack_signature_ok($raw, is_string($signature) ? $signature : null)) {
        lotal_json(['ok' => false, 'error' => 'Invalid signature.'], 401);
    }

    $event = json_decode($raw, true);
    if (!is_array($event)) {
        lotal_json(['ok' => false, 'error' => 'Malformed payload.'], 400);
    }

    lotal_json(lotal_paystack_handle_event($event));
}

/* ------------------------------------------------------------------ */
/* admin console                                                       */
/* ------------------------------------------------------------------ */

function lotal_handle_admin(string $route): void
{
    // Make sure the operator account exists even on a database that was created
    // by hand (idempotent, one indexed lookup).
    try {
        lotal_ensure_admin();
    } catch (Throwable) {
        /* table missing — the error page explains how to run /install */
    }

    $action = lotal_post('action');

    if ($action === 'admin_login') {
        $result = lotal_login(lotal_post('email'), lotal_post('password'));
        if (!$result['ok'] || ($result['user']['role'] ?? '') !== 'admin') {
            echo lotal_page_admin_login('Invalid administrator credentials.');
            return;
        }
        lotal_redirect('/admin');
    }

    if (!lotal_is_admin()) {
        if ($action === 'admin_logout') {
            lotal_logout();
        }
        echo lotal_page_admin_login();
        return;
    }

    if ($action === 'admin_logout') {
        lotal_logout();
        lotal_flash('Signed out of the console.');
        lotal_redirect('/admin');
    }

    if ($action === 'admin_status') {
        $orderId = lotal_post('order_id');
        $status = lotal_post('status');
        $order = lotal_one('SELECT code FROM orders WHERE id = ?', [$orderId]);
        if ($order && array_key_exists($status, lotal_statuses())) {
            lotal_advance_order(
                (string) $order['code'],
                $status,
                'Status set to ' . lotal_status_label($status) . ' by operations.'
            );
            lotal_flash('Order ' . $order['code'] . ' moved to ' . lotal_status_label($status) . '. The customer timeline is now live.');
        }
        lotal_redirect('/admin?tab=orders');
    }

    if ($action === 'admin_product') {
        $productId = lotal_post('product_id');
        $price = lotal_post('price');
        $stock = lotal_post('stock');
        lotal_exec(
            'UPDATE products SET price = ?, stock = ? WHERE id = ?',
            [(int) round(((float) str_replace(',', '', $price)) * 100), max(0, (int) $stock), $productId]
        );
        lotal_flash('Inventory updated.');
        lotal_redirect('/admin?tab=inventory');
    }

    $tab = in_array(lotal_get('tab'), ['orders', 'inventory', 'customers', 'payments'], true)
        ? lotal_get('tab')
        : 'orders';

    echo lotal_page_admin(
        lotal_admin_stats(),
        lotal_all_orders(),
        lotal_products(['sort' => 'newest']),
        lotal_customers(),
        $tab,
        lotal_current_user() ?? []
    );
}

/* ------------------------------------------------------------------ */
/* installer                                                           */
/* ------------------------------------------------------------------ */

function lotal_handle_install(): never
{
    if (lotal_env('ALLOW_INSTALL', '1') !== '1') {
        lotal_json(['ok' => false, 'error' => 'INSTALL is disabled (set ALLOW_INSTALL=1 to re-enable).'], 403);
    }

    $file = LOTAL_ROOT . '/schema.sql';
    $pdo = lotal_pdo();
    if (!is_readable($file)) {
        lotal_json(['ok' => false, 'error' => 'schema.sql not found at ' . $file], 500);
    }

    $sql = (string) file_get_contents($file);
    $applied = 0;
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
        // drop comment lines, then skip anything that is now empty
        $lines = preg_filter('/^\s*--.*$/m', '', (string) $statement) ?? [];
        $statement = trim(implode("\n", (array) $lines));
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $applied++;
    }

    lotal_ensure_admin();

    $productCount = (int) (lotal_one('SELECT COUNT(*)::int AS c FROM products')['c'] ?? 0);
    if (lotal_is_post() && lotal_post('run') === '1') {
        echo '<pre>Applied ' . $applied . " statements.\nProducts in catalogue: {$productCount}\n</pre>";
    }

    lotal_json([
        'ok'        => true,
        'applied'   => $applied,
        'products'  => $productCount,
        'admin'     => lotal_config()['admin']['email'],
        'next'      => 'Open /shop, then /admin.html to sign in to the console.',
        'warranty'  => lotal_config()['warranty_days'],
    ]);
}
