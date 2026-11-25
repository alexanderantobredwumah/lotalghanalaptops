<?php
/**
 * LOTAL GHANA — standalone Paystack webhook receiver.
 *
 * The front controller already handles /paystack/webhook; this file exists so the
 * exact URL https://your-domain/api/webhook.php can be pasted into the Paystack
 * dashboard on hosts that do not rewrite paths (shared hosting, cPanel, etc.).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api/inc/bootstrap.php';
require dirname(__DIR__) . '/api/inc/db.php';
require dirname(__DIR__) . '/api/inc/auth.php';
require dirname(__DIR__) . '/api/inc/store.php';
require dirname(__DIR__) . '/api/inc/paystack.php';

header('Content-Type: application/json; charset=utf-8');

$raw = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null;

if (!lotal_paystack_signature_ok($raw, is_string($signature) ? $signature : null)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature.']);
    exit;
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Malformed payload.']);
    exit;
}

echo json_encode(lotal_paystack_handle_event($event));
