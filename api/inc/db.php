<?php
/**
 * LOTAL GHANA — PostgreSQL data access (Supabase compatible)
 * PDO only, no ORM, no dependencies. Queries are prepared statements.
 */

declare(strict_types=1);

function lotal_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = lotal_config();
    $dsn = $config['db']['pool'] ?: $config['db']['url'];

    if ($dsn === '') {
        throw new RuntimeException(
            'No database configured. Set DATABASE_URL (or PGBOUNCER_URL) in php/.env — '
            . 'Supabase → Project Settings → Database → Connection string.'
        );
    }

    // Accept postgres:// / postgresql:// URLs from Supabase and Vercel.
    if (str_starts_with($dsn, 'postgres')) {
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        $pdo = new PDO($dsn, lotal_env('PGUSER', 'postgres'), lotal_env('PGPASSWORD'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    $pdo->exec("SET TIME ZONE 'Africa/Accra'");
    return $pdo;
}

/** @param array<int,mixed> $params */
function lotal_all(string $sql, array $params = []): array
{
    $statement = lotal_pdo()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

/** @param array<int,mixed> $params */
function lotal_one(string $sql, array $params = []): ?array
{
    $rows = lotal_all($sql . (str_contains(strtolower($sql), 'limit') ? '' : ' LIMIT 1'), $params);
    return $rows[0] ?? null;
}

/** @param array<int,mixed> $params */
function lotal_exec(string $sql, array $params = []): int
{
    $statement = lotal_pdo()->prepare($sql);
    $statement->execute($params);
    return $statement->rowCount();
}

/* ------------------------------------------------------------------ */
/* database-backed sessions (serverless-safe: no local session files)  */
/* ------------------------------------------------------------------ */

const LOTAL_SESSION_COOKIE = 'lotal_php_session';
const LOTAL_SESSION_DAYS = 30;

function lotal_session_token(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    $incoming = (string) ($_COOKIE[LOTAL_SESSION_COOKIE] ?? '');
    if ($incoming !== '' && preg_match('/^[a-f0-9]{64}$/', $incoming)) {
        return $token = $incoming;
    }
    return $token = bin2hex(random_bytes(32));
}

function lotal_session_row(): ?array
{
    static $row = null;
    if ($row !== null) {
        return $row;
    }
    try {
        $row = lotal_one('SELECT * FROM php_sessions WHERE token = ?', [lotal_session_token()]) ?? [];
    } catch (Throwable) {
        $row = [];
    }
    if ($row && strtotime((string) $row['expires_at']) < time()) {
        lotal_exec('DELETE FROM php_sessions WHERE token = ?', [lotal_session_token()]);
        $row = [];
    }
    return $row;
}

function lotal_session(): array
{
    $row = lotal_session_row();
    if (!$row) {
        return [];
    }
    $data = json_decode((string) ($row['data'] ?? '{}'), true);
    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $patch */
function lotal_session_put(array $patch): void
{
    $data = array_merge(lotal_session(), $patch);
    $token = lotal_session_token();
    $expires = date('Y-m-d H:i:s', time() + LOTAL_SESSION_DAYS * 86400);

    lotal_pdo()->prepare(
        'INSERT INTO php_sessions (token, user_id, data, expires_at)
         VALUES (?, ?, ?::jsonb, ?)
         ON CONFLICT (token) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at'
    )->execute([$token, $data['user_id'] ?? null, json_encode($data, JSON_UNESCAPED_UNICODE), $expires]);

    if (!headers_sent()) {
        setcookie(LOTAL_SESSION_COOKIE, $token, [
            'expires'  => time() + LOTAL_SESSION_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => str_starts_with(lotal_config()['site_url'], 'https://'),
        ]);
    }
}

function lotal_session_forget(): void
{
    $token = lotal_session_token();
    try {
        lotal_exec('DELETE FROM php_sessions WHERE token = ?', [$token]);
    } catch (Throwable) {
        /* table may not exist yet — nothing to clean */
    }
    lotal_session_put(['user_id' => null, 'cart' => [], 'csrf' => bin2hex(random_bytes(24))]);
}

/* ------------------------------------------------------------------ */
/* catalogue                                                           */
/* ------------------------------------------------------------------ */

function lotal_product_map(array $row): array
{
    $image = (string) ($row['image'] ?? '');
    return [
        'id'            => $row['id'],
        'slug'          => $row['slug'],
        'brand'         => $row['brand'],
        'name'          => $row['name'],
        'tagline'       => $row['tagline'],
        'price'         => (int) $row['price'],
        'old_price'     => $row['old_price'] !== null ? (int) $row['old_price'] : null,
        'stock'         => (int) $row['stock'],
        'grade'         => $row['grade'],
        'grade_note'    => $row['grade_note'],
        'processor'     => $row['processor'],
        'ram'           => $row['ram'],
        'storage'       => $row['storage'],
        'screen'        => $row['screen'],
        'graphics'      => $row['graphics'],
        'battery_health'=> (int) $row['battery_health'],
        'os'            => $row['os'],
        'ports'         => $row['ports'],
        'weight'        => $row['weight'],
        'image'         => str_starts_with($image, '/') ? $image : '/' . $image,
        'highlights'    => is_string($row['highlights'] ?? null)
            ? (json_decode((string) $row['highlights'], true) ?: [])
            : ($row['highlights'] ?? []),
        'featured'      => !empty($row['featured']),
    ];
}

const LOTAL_PRODUCT_COLUMNS = 'id, slug, brand, name, tagline, price, old_price, stock, grade, grade_note,
    processor, ram, storage, screen, graphics, battery_health, os, ports, weight, image, highlights, featured';

/** @param array{brand?:string,q?:string,sort?:string,featured_only?:bool} $filters */
function lotal_products(array $filters = []): array
{
    $where = [];
    $params = [];

    if (!empty($filters['brand']) && $filters['brand'] !== 'All') {
        $where[] = 'brand = ?';
        $params[] = $filters['brand'];
    }
    if (!empty($filters['q'])) {
        $like = '%' . strtolower($filters['q']) . '%';
        $where[] = '(lower(name) LIKE ? OR lower(brand) LIKE ? OR lower(processor) LIKE ? OR lower(tagline) LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($filters['featured_only'])) {
        $where[] = 'featured = true';
    }

    $order = match ($filters['sort'] ?? 'featured') {
        'price-asc'  => 'price ASC',
        'price-desc' => 'price DESC',
        'newest'     => 'created_at DESC',
        default      => 'featured DESC, name ASC',
    };

    $sql = 'SELECT ' . LOTAL_PRODUCT_COLUMNS . ' FROM products';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY {$order}";

    return array_map('lotal_product_map', lotal_all($sql, $params));
}

function lotal_product(string $slug): ?array
{
    $row = lotal_one('SELECT ' . LOTAL_PRODUCT_COLUMNS . ' FROM products WHERE slug = ?', [$slug]);
    return $row ? lotal_product_map($row) : null;
}

function lotal_product_by_id(string $id): ?array
{
    $row = lotal_one('SELECT ' . LOTAL_PRODUCT_COLUMNS . ' FROM products WHERE id = ?', [$id]);
    return $row ? lotal_product_map($row) : null;
}

function lotal_related(string $brand, string $excludeId, int $limit = 3): array
{
    $rows = lotal_all(
        'SELECT ' . LOTAL_PRODUCT_COLUMNS . ' FROM products
         WHERE brand = ? AND id <> ? ORDER BY featured DESC, name ASC LIMIT ' . (int) $limit,
        [$brand, $excludeId]
    );
    return array_map('lotal_product_map', $rows);
}

function lotal_brands(): array
{
    return lotal_all('SELECT brand, COUNT(*)::int AS count FROM products GROUP BY brand ORDER BY brand');
}

function lotal_testimonials(): array
{
    return lotal_all('SELECT * FROM testimonials ORDER BY id LIMIT 12');
}

/* ------------------------------------------------------------------ */
/* orders + tracking                                                   */
/* ------------------------------------------------------------------ */

function lotal_order_items(string $orderId): array
{
    return lotal_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY title', [$orderId]);
}

function lotal_order_events(string $orderId): array
{
    return lotal_all('SELECT * FROM order_events WHERE order_id = ? ORDER BY created_at ASC', [$orderId]);
}

function lotal_order_by_code(string $code): ?array
{
    $order = lotal_one('SELECT * FROM orders WHERE code = ?', [lotal_normalise_code($code)]);
    if (!$order) {
        return null;
    }
    $order['items']  = lotal_order_items((string) $order['id']);
    $order['events'] = lotal_order_events((string) $order['id']);
    return $order;
}

function lotal_orders_for_user(string $userId): array
{
    $orders = lotal_all('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    foreach ($orders as &$order) {
        $order['items']  = lotal_order_items((string) $order['id']);
        $order['events'] = lotal_order_events((string) $order['id']);
    }
    return $orders;
}

function lotal_all_orders(int $limit = 200): array
{
    $orders = lotal_all('SELECT * FROM orders ORDER BY created_at DESC LIMIT ' . (int) $limit);
    foreach ($orders as &$order) {
        $order['items'] = lotal_all('SELECT title, qty, unit_price FROM order_items WHERE order_id = ?', [(string) $order['id']]);
    }
    return $orders;
}

function lotal_admin_stats(): array
{
    $orders   = lotal_one('SELECT COUNT(*)::int AS c FROM orders');
    $products = lotal_one('SELECT COUNT(*)::int AS c FROM products');
    $revenue  = lotal_one('SELECT COALESCE(SUM(total),0)::int AS t FROM orders');
    $customers= lotal_one("SELECT COUNT(*)::int AS c FROM users WHERE role = 'customer'");
    $unpaid   = lotal_one("SELECT COUNT(*)::int AS c FROM orders WHERE status = 'placed'");

    return [
        'orders'    => (int) ($orders['c'] ?? 0),
        'products'  => (int) ($products['c'] ?? 0),
        'revenue'   => (int) ($revenue['t'] ?? 0),
        'customers' => (int) ($customers['c'] ?? 0),
        'unpaid'    => (int) ($unpaid['c'] ?? 0),
    ];
}

/**
 * Move an order along the rail. Refuses to move backwards so a replayed
 * Paystack webhook can never corrupt history.
 */
function lotal_advance_order(string $code, string $status, string $note, ?string $paymentReference = null): bool
{
    $order = lotal_one('SELECT id, status FROM orders WHERE code = ?', [lotal_normalise_code($code)]);
    if (!$order) {
        return false;
    }
    $current = lotal_status_index((string) $order['status']);
    $next    = lotal_status_index($status);
    if ($next <= $current && $paymentReference === null) {
        return false;
    }

    $pdo = lotal_pdo();
    $pdo->beginTransaction();
    try {
        if ($paymentReference !== null) {
            lotal_exec(
                "UPDATE orders SET status = ?, updated_at = now(), payment_reference = ?,
                 payment_method = CASE WHEN payment_method = 'paystack' THEN 'card' ELSE payment_method END
                 WHERE id = ?",
                [$status, $paymentReference, $order['id']]
            );
        } else {
            lotal_exec('UPDATE orders SET status = ?, updated_at = now() WHERE id = ?', [$status, $order['id']]);
        }
        lotal_exec(
            'INSERT INTO order_events (order_id, status, title, note) VALUES (?, ?, ?, ?)',
            [$order['id'], $status, lotal_status_label($status), $note]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return true;
}

function lotal_customers(): array
{
    return lotal_all(
        "SELECT u.id, u.full_name, u.email, u.phone, u.city, u.role, u.created_at,
                (SELECT COUNT(*)::int FROM orders o WHERE o.user_id = u.id) AS orders,
                (SELECT COALESCE(SUM(o.total),0)::int FROM orders o WHERE o.user_id = u.id) AS spent
         FROM users u ORDER BY u.created_at DESC LIMIT 200"
    );
}
