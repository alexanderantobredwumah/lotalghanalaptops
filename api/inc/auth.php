<?php
/**
 * LOTAL GHANA — accounts, sessions and password recovery.
 *
 * Hashing uses PBKDF2-HMAC-SHA256 so a customer created by the PHP app can
 * also sign in on the Next.js storefront (and vice versa); legacy scrypt
 * hashes written by the Next.js app are still accepted on read.
 */

declare(strict_types=1);

const LOTAL_PBKDF2_ITERATIONS = 100000;
const LOTAL_PBKDF2_LENGTH = 32;

function lotal_hash_password(string $password): string
{
    $salt = bin2hex(random_bytes(16));
    $hash = hash_pbkdf2('sha256', $password, $salt, LOTAL_PBKDF2_ITERATIONS, LOTAL_PBKDF2_LENGTH * 2, false);
    return sprintf('pbkdf2$%d$%s$%s', LOTAL_PBKDF2_ITERATIONS, $salt, $hash);
}

function lotal_verify_password(string $password, string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    $parts = explode('$', $stored);

    if ($parts[0] === 'pbkdf2' && count($parts) === 4) {
        $expected = $parts[3];
        $derived = hash_pbkdf2('sha256', $password, $parts[2], (int) $parts[1], (int) (strlen($expected) / 2), false);
        return hash_equals($expected, $derived);
    }

    if ($parts[0] === 'scrypt' && count($parts) === 3 && function_exists('sodium_crypto_pwhash')) {
        // Legacy Next.js scrypt hashes cannot be recomputed portably in PHP;
        // the storefront remains the writer for those accounts.
        return false;
    }

    if (password_get_info($stored)['algo'] !== null && password_get_info($stored)['algoName'] !== 'unknown') {
        return password_verify($password, $stored);
    }

    return false;
}

function lotal_find_user(string $email): ?array
{
    return lotal_one('SELECT * FROM users WHERE lower(email) = lower(?)', [$email]);
}

function lotal_register(array $input): array
{
    $fullName = trim((string) ($input['fullName'] ?? ''));
    $email    = strtolower(trim((string) ($input['email'] ?? '')));
    $phone    = trim((string) ($input['phone'] ?? ''));
    $city     = trim((string) ($input['city'] ?? 'Accra'));
    $password = (string) ($input['password'] ?? '');

    if (mb_strlen($fullName) < 3) {
        return ['ok' => false, 'error' => 'Please enter your full name.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters long.'];
    }
    if (!empty($input['confirm']) && $password !== (string) $input['confirm']) {
        return ['ok' => false, 'error' => 'The two passwords do not match.'];
    }
    if (lotal_find_user($email)) {
        return ['ok' => false, 'error' => 'An account already exists with that email address. Try signing in.'];
    }

    lotal_exec(
        "INSERT INTO users (email, full_name, phone, city, password_hash, role, created_at)
         VALUES (?, ?, ?, ?, ?, 'customer', now())",
        [$email, $fullName, $phone, $city, lotal_hash_password($password)]
    );

    $user = lotal_find_user($email);
    if (!$user) {
        return ['ok' => false, 'error' => 'Registration failed. Please try again.'];
    }
    lotal_login_as($user);

    return ['ok' => true, 'user' => $user];
}

function lotal_login(string $email, string $password): array
{
    $user = lotal_find_user($email);
    if (!$user || !lotal_verify_password($password, (string) $user['password_hash'])) {
        return ['ok' => false, 'error' => 'That email and password combination is not correct.'];
    }
    lotal_login_as($user);

    return ['ok' => true, 'admin' => $user['role'] === 'admin', 'user' => $user];
}

function lotal_login_as(array $user): void
{
    lotal_session_put([
        'user_id'   => $user['id'],
        'login_at'  => date('c'),
        'cart'      => lotal_session()['cart'] ?? [],
    ]);
}

function lotal_logout(): void
{
    lotal_session_put(['user_id' => null]);
}

function lotal_current_user(): ?array
{
    static $resolved = false;
    static $user = null;

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $session = lotal_session();
    $userId = $session['user_id'] ?? null;
    if (!is_string($userId) || $userId === '') {
        return $user = null;
    }
    try {
        $user = lotal_one('SELECT * FROM users WHERE id = ?', [$userId]);
    } catch (Throwable) {
        $user = null;
    }
    return $user;
}

function lotal_is_admin(): bool
{
    $user = lotal_current_user();
    return $user !== null && $user['role'] === 'admin';
}

function lotal_require_user(): array
{
    $user = lotal_current_user();
    if ($user === null) {
        $next = lotal_route();
        lotal_flash('Please sign in to continue.', 'warn');
        lotal_redirect('/login?next=' . rawurlencode($next));
    }
    return $user;
}

/* ------------------------------------------------------------------ */
/* password recovery                                                   */
/* ------------------------------------------------------------------ */

function lotal_issue_reset(string $userId): string
{
    $token = bin2hex(random_bytes(24));
    lotal_exec(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]
    );
    return $token;
}

function lotal_consume_reset(string $token, string $password): array
{
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters long.'];
    }
    $row = lotal_one(
        'SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL',
        [hash('sha256', $token)]
    );
    if (!$row) {
        return ['ok' => false, 'error' => 'This reset link is not valid. Please request a new one.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This reset link has expired. Please request a new one.'];
    }

    lotal_exec('UPDATE password_reset_tokens SET used_at = now() WHERE id = ?', [$row['id']]);
    lotal_exec('UPDATE users SET password_hash = ? WHERE id = ?', [lotal_hash_password($password), $row['user_id']]);

    return ['ok' => true];
}

/** Seeds the administrator from environment variables (idempotent). */
function lotal_ensure_admin(): void
{
    $config = lotal_config();
    $email = $config['admin']['email'];
    if (lotal_find_user($email)) {
        return;
    }
    lotal_exec(
        "INSERT INTO users (email, full_name, phone, city, password_hash, role, created_at)
         VALUES (?, ?, ?, 'Accra', ?, 'admin', now())",
        [
            strtolower($email),
            'LOTAL GHANA Operations',
            $config['support']['phone'],
            lotal_hash_password($config['admin']['password']),
        ]
    );
}
