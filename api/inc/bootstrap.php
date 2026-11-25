<?php
/**
 * LOTAL GHANA — PHP application bootstrap
 * Configuration, helpers, security and the tracking-code algorithm.
 * This file must be required before anything else.
 */

declare(strict_types=1);

define('LOTAL_ROOT', dirname(__DIR__, 2));
define('LOTAL_INC', __DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Africa/Accra');

/* ------------------------------------------------------------------ */
/* .env loader                                                         */
/* ------------------------------------------------------------------ */

function lotal_env_load(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    foreach ([LOTAL_ROOT . '/.env', LOTAL_ROOT . '/.env.local'] as $file) {
        if (!is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

function lotal_env(string $key, string $default = ''): string
{
    lotal_env_load();
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

/** Single source of truth for every tunable in the system. */
function lotal_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $secret = lotal_env('PAYSTACK_SECRET_KEY');
    $public = lotal_env('PAYSTACK_PUBLIC_KEY', lotal_env('NEXT_PUBLIC_PAYSTACK_PUBLIC_KEY'));
    $realKey = static fn (?string $v, string $prefix): bool => is_string($v)
        && strlen($v) > 20
        && str_starts_with($v, $prefix)
        && !preg_match('/YOUR_|CHANGE|xxxx/i', $v);

    return $config = [
        'site_name'   => 'LOTAL GHANA',
        'site_url'    => rtrim(lotal_env('SITE_URL', lotal_env('NEXT_PUBLIC_SITE_URL', 'http://localhost:8080')), '/'),
        'support'     => [
            'phone'   => lotal_env('SUPPORT_PHONE', '+233 30 274 1188'),
            'phone2'  => lotal_env('SUPPORT_PHONE_ALT', '+233 55 190 4477'),
            'email'   => lotal_env('SUPPORT_EMAIL', 'support@lotalghana.com'),
            'address' => lotal_env('SHOP_ADDRESS', '14 Oxford Street, Osu — Accra'),
            'hours'   => 'Mon–Sat 8am–7pm · Sun 12pm–5pm',
        ],
        'warranty_days'      => (int) lotal_env('WARRANTY_DAYS', '14'),
        'return_days'        => (int) lotal_env('RETURN_DAYS', '7'),
        'diagnostic_points'  => (int) lotal_env('DIAGNOSTIC_POINTS', '32'),
        'currency'           => lotal_env('CURRENCY', 'GHS'),
        'currency_symbol'    => 'GH₵',
        'delivery_free_over' => (int) lotal_env('FREE_DELIVERY_OVER', '800000'),
        'db' => [
            'url'      => lotal_env('DATABASE_URL', lotal_env('POSTGRES_URL', lotal_env('SUPABASE_DB_URL'))),
            'pool'     => lotal_env('PGBOUNCER_URL'),
        ],
        'admin' => [
            'email'    => lotal_env('ADMIN_EMAIL', 'admin@lotalghana.com'),
            'password' => lotal_env('ADMIN_PASSWORD', 'Lotal@2026'),
        ],
        'paystack' => [
            'secret_key'  => $secret,
            'public_key'  => $public,
            'enabled'     => $realKey($secret, 'sk_') && $realKey($public, 'pk_'),
            'mode'        => str_starts_with($secret, 'sk_live_') ? 'live' : 'test',
            'currency'    => lotal_env('PAYSTACK_CURRENCY', 'GHS'),
            'api_base'    => rtrim(lotal_env('PAYSTACK_API_BASE', 'https://api.paystack.co'), '/'),
            'bank_name'   => lotal_env('PAYSTACK_BANK_NAME', 'GCB Bank'),
            'bank_account'=> lotal_env('PAYSTACK_BANK_ACCOUNT', '—'),
            'bank_holder' => lotal_env('PAYSTACK_BANK_HOLDER', 'LOTAL GHANA LTD'),
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* output helpers                                                      */
/* ------------------------------------------------------------------ */

function lotal_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Money is stored in pesewas (GH₵ x 100) — the same minor unit Paystack bills. */
function lotal_money(int $pesewas, bool $short = false): string
{
    $cedis = $pesewas / 100;
    $formatted = $short && floor($cedis) === $cedis
        ? number_format($cedis, 0)
        : number_format($cedis, 2);
    return lotal_config()['currency_symbol'] . $formatted;
}

function lotal_date(string $timestamp, bool $withTime = true): string
{
    if ($timestamp === '') {
        return '—';
    }
    $time = strtotime($timestamp);
    if ($time === false) {
        return '—';
    }
    return $withTime
        ? date('d M · H:i', $time) . ' GMT'
        : date('d M Y', $time);
}

function lotal_rel(string $timestamp): string
{
    $time = strtotime($timestamp);
    if ($time === false) {
        return '—';
    }
    $diff = time() - $time;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' hr ago';
    }
    return floor($diff / 86400) . ' day(s) ago';
}

/* ------------------------------------------------------------------ */
/* store policy                                                        */
/* ------------------------------------------------------------------ */

function lotal_statuses(): array
{
    $warranty = lotal_config()['warranty_days'];
    return [
        'placed' => [
            'label' => 'Order placed',
            'short' => 'Placed',
            'note'  => 'Your order has been received and assigned a tracking code.',
        ],
        'paid' => [
            'label' => 'Payment confirmed',
            'short' => 'Paid',
            'note'  => 'Payment received and receipt issued to your email address.',
        ],
        'packing' => [
            'label' => 'Diagnostics & packing',
            'short' => 'Packing',
            'note'  => "Final check completed. Unit packed with charger and sleeve.",
        ],
        'dispatched' => [
            'label' => 'Dispatched from Accra',
            'short' => 'Dispatched',
            'note'  => 'Handed to the LOTAL delivery desk. Leaving our Osu workshop.',
        ],
        'out_for_delivery' => [
            'label' => 'Out for delivery',
            'short' => 'Out for delivery',
            'note'  => 'Rider is on the way to your address. Keep your phone close.',
        ],
        'delivered' => [
            'label' => 'Delivered',
            'short' => 'Delivered',
            'note'  => "Package received and signed for. The {$warranty}-day warranty is now active.",
        ],
    ];
}

function lotal_status_label(string $status, string $key = 'label'): string
{
    $all = lotal_statuses();
    return $all[$status][$key] ?? ucfirst(str_replace('_', ' ', $status));
}

function lotal_status_index(string $status): int
{
    return (int) array_search($status, array_keys(lotal_statuses()), true);
}

function lotal_payment_labels(): array
{
    return [
        'paystack' => 'Paystack — card, bank or mobile money',
        'momo'     => 'MTN Mobile Money (Paystack)',
        'telecel'  => 'Telecel Cash (Paystack)',
        'at'       => 'AT Money (Paystack)',
        'card'     => 'Visa / Mastercard (Paystack)',
        'transfer' => 'Bank transfer',
        'delivery' => 'Pay on delivery (cash)',
    ];
}

function lotal_payment_label(string $method): string
{
    $labels = lotal_payment_labels();
    return $labels[$method] ?? $method;
}

/** Methods that must be settled online before dispatch. */
function lotal_needs_payment(string $method): bool
{
    return in_array($method, ['paystack', 'momo', 'telecel', 'at', 'card', 'transfer'], true);
}

function lotal_cities(): array
{
    return ['Accra', 'Tema', 'Kumasi', 'Takoradi', 'Cape Coast', 'Tamale', 'Other'];
}

function lotal_delivery_fee(string $city): int
{
    $fees = [
        'Accra' => 4500, 'Tema' => 5500, 'Kumasi' => 8500, 'Takoradi' => 9500,
        'Cape Coast' => 9500, 'Tamale' => 12500, 'Other' => 14500,
    ];
    return $fees[$city] ?? $fees['Other'];
}

/* ------------------------------------------------------------------ */
/* tracking codes: LT-XXXX-XXXX-C                                      */
/* ------------------------------------------------------------------ */

const LOTAL_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function lotal_random_block(int $length): string
{
    $out = '';
    $bytes = random_bytes($length);
    for ($i = 0; $i < $length; $i++) {
        $out .= LOTAL_ALPHABET[ord($bytes[$i]) % strlen(LOTAL_ALPHABET)];
    }
    return $out;
}

/** Single check character so mistyped codes fail fast with a clear message. */
function lotal_code_checksum(string $body): string
{
    $sum = 0;
    for ($i = 0, $len = strlen($body); $i < $len; $i++) {
        $sum = ($sum * 31 + ord($body[$i])) % 31;
    }
    return LOTAL_ALPHABET[$sum % strlen(LOTAL_ALPHABET)];
}

function lotal_new_tracking_code(): string
{
    $body = lotal_random_block(4) . lotal_random_block(4);
    return sprintf('LT-%s-%s-%s', substr($body, 0, 4), substr($body, 4, 4), lotal_code_checksum($body));
}

function lotal_normalise_code(string $code): string
{
    $upper = strtoupper(trim($code));
    if (preg_match('/^LT[-\s]?([A-Z2-9]{4})[-\s]?([A-Z2-9]{4})[-\s]?([A-Z2-9])$/', $upper, $m)) {
        return "LT-{$m[1]}-{$m[2]}-{$m[3]}";
    }
    return $upper;
}

function lotal_valid_code(string $code): bool
{
    if (!preg_match('/^LT-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9])$/', lotal_normalise_code($code), $m)) {
        return false;
    }
    return lotal_code_checksum($m[1] . $m[2]) === $m[3];
}

/** Paystack references are the tracking code without dashes — reversible. */
function lotal_reference_for(string $code): string
{
    return str_replace('-', '', $code);
}

function lotal_code_for_reference(string $reference): string
{
    return lotal_normalise_code($reference);
}

/* ------------------------------------------------------------------ */
/* request / security helpers                                          */
/* ------------------------------------------------------------------ */

function lotal_is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function lotal_route(): string
{
    if (isset($_GET['r'])) {
        return '/' . trim((string) $_GET['r'], '/');
    }
    if (!empty($_SERVER['PATH_INFO'])) {
        return '/' . trim((string) $_SERVER['PATH_INFO'], '/');
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/api#', '', $path) ?? $path;
    $path = preg_replace('#/index\.php$#', '/', $path) ?? $path;
    return '/' . trim($path, '/');
}

function lotal_url(string $path = '/'): string
{
    if (str_starts_with($path, 'http')) {
        return $path;
    }
    return lotal_config()['site_url'] . '/' . ltrim($path, '/');
}

function lotal_asset(string $path): string
{
    return lotal_config()['site_url'] . '/assets/' . ltrim($path, '/');
}

function lotal_post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function lotal_get(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function lotal_redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : lotal_url($path)));
    exit;
}

function lotal_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function lotal_flash(?string $message = null, string $type = 'ok'): array|string|null
{
    $session = lotal_session();
    if ($message === null) {
        $flash = $session['flash'] ?? null;
        lotal_session_put(['flash' => null]);
        return is_array($flash) ? $flash : null;
    }
    lotal_session_put(['flash' => ['message' => $message, 'type' => $type]]);
    return null;
}

function lotal_csrf_token(): string
{
    $session = lotal_session();
    if (!empty($session['csrf'])) {
        return $session['csrf'];
    }
    $token = bin2hex(random_bytes(24));
    lotal_session_put(['csrf' => $token]);
    return $token;
}

function lotal_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . lotal_e(lotal_csrf_token()) . '">';
}

function lotal_csrf_verify(): void
{
    if (!lotal_is_post()) {
        return;
    }
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(lotal_csrf_token(), $sent)) {
        lotal_json(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.'], 419);
    }
}
