<?php
/**
 * LOTAL GHANA — Paystack (Ghana) gateway.
 *
 * Keys come from the deployment environment only:
 *   PAYSTACK_SECRET_KEY  sk_live_… / sk_test_…   (server)
 *   PAYSTACK_PUBLIC_KEY  pk_live_… / pk_test_…   (browser inline checkout)
 *   PAYSTACK_CURRENCY    GHS
 *
 * Amounts in this application are stored in pesewas, which is the minor unit
 * Paystack bills for GHS — so the total is passed straight through.
 */

declare(strict_types=1);

function lotal_paystack(): array
{
    return lotal_config()['paystack'];
}

function lotal_paystack_enabled(): bool
{
    return lotal_paystack()['enabled'];
}

function lotal_paystack_channels(string $method): array
{
    return match ($method) {
        'momo', 'telecel', 'at' => ['mobile_money'],
        'card'                  => ['card'],
        'transfer'              => ['bank_transfer'],
        default                 => ['card', 'bank', 'mobile_money', 'ussd', 'qr', 'bank_transfer'],
    };
}

/** @param array{path:string,method?:string,payload?:array} $options */
function lotal_paystack_request(string $path, string $method = 'GET', array $payload = []): array
{
    $gateway = lotal_paystack();
    if ($gateway['secret_key'] === '') {
        return ['ok' => false, 'error' => 'PAYSTACK_SECRET_KEY is not set.'];
    }

    $url = $gateway['api_base'] . $path;
    $headers = [
        'Authorization: Bearer ' . $gateway['secret_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Paystack is unreachable: ' . $curlError];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body ?? '',
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $status = 200;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Paystack is unreachable right now.'];
        }
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => "Paystack responded with HTTP {$status}."];
    }
    if ($status >= 400 || ($decoded['status'] ?? false) === false) {
        return ['ok' => false, 'error' => (string) ($decoded['message'] ?? "Paystack error (HTTP {$status}).")];
    }

    return ['ok' => true, 'data' => $decoded['data'] ?? []];
}

/** Step 1 — create a hosted checkout session for an unpaid order. */
function lotal_paystack_initialize(array $order): array
{
    $gateway = lotal_paystack();
    $reference = lotal_reference_for((string) $order['code']);

    $response = lotal_paystack_request('/transaction/initialize', 'POST', [
        'email'        => $order['email'],
        'amount'       => (int) $order['total'],
        'currency'     => $gateway['currency'],
        'reference'    => $reference,
        'callback_url' => lotal_url('/paystack/callback?reference=' . $reference),
        'channels'     => lotal_paystack_channels((string) $order['payment_method']),
        'metadata'     => [
            'order_code'   => $order['code'],
            'customer'     => $order['full_name'],
            'phone'        => $order['phone'],
            'delivery_city'=> $order['city'],
            'custom_fields' => [
                ['display_name' => 'Order', 'variable_name' => 'order_code', 'value' => $order['code']],
                ['display_name' => 'Customer', 'variable_name' => 'customer_name', 'value' => $order['full_name']],
            ],
        ],
    ]);

    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error']];
    }

    return [
        'ok'               => true,
        'authorization_url'=> $response['data']['authorization_url'] ?? '',
        'access_code'      => $response['data']['access_code'] ?? '',
        'reference'        => $reference,
        'public_key'       => $gateway['public_key'],
        'currency'         => $gateway['currency'],
        'amount'           => (int) $order['total'],
        'mode'             => $gateway['mode'],
    ];
}

/** Step 2 — confirm the charge server-side, then move the order to paid. */
function lotal_paystack_confirm(string $reference): array
{
    $reference = strtoupper(trim($reference));
    if ($reference === '') {
        return ['ok' => false, 'error' => 'Missing payment reference.'];
    }

    $response = lotal_paystack_request('/transaction/verify/' . rawurlencode($reference));
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error']];
    }

    $data = $response['data'];
    if (($data['status'] ?? '') !== 'success') {
        return ['ok' => false, 'error' => 'Paystack reports this payment as "' . ($data['status'] ?? 'pending') . '".'];
    }

    $amount = (int) ($data['amount'] ?? 0);
    $code = lotal_code_for_reference($reference);
    $order = lotal_order_by_code($code);
    if (!$order) {
        return ['ok' => false, 'error' => 'No order matches that payment reference.'];
    }
    if ($amount > 0 && $amount !== (int) $order['total']) {
        return ['ok' => false, 'error' => 'The amount paid does not match this order. Support has been alerted.'];
    }

    $channel = (string) ($data['channel'] ?? 'Paystack');
    lotal_advance_order(
        $code,
        'paid',
        sprintf(
            'Paystack confirmed %s %s via %s. Reference %s.',
            lotal_money($amount),
            (string) ($data['currency'] ?? 'GHS'),
            $channel,
            $reference
        ),
        $reference
    );

    return ['ok' => true, 'code' => $code, 'channel' => $channel, 'amount' => $amount];
}

/** Webhooks are authenticated with an SHA-512 HMAC over the raw body. */
function lotal_paystack_signature_ok(string $rawBody, ?string $signature): bool
{
    if (!$signature) {
        return false;
    }
    $secret = lotal_paystack()['secret_key'];
    if ($secret === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower($signature));
}

/** Handles a verified webhook event payload. */
function lotal_paystack_handle_event(array $event): array
{
    $name = (string) ($event['event'] ?? '');
    $data = (array) ($event['data'] ?? []);
    $reference = strtoupper((string) ($data['reference'] ?? ''));
    if ($reference === '') {
        return ['ok' => true, 'ignored' => 'no reference'];
    }
    $code = lotal_code_for_reference($reference);

    if (in_array($name, ['charge.success', 'subscription.create'], true) && ($data['status'] ?? '') === 'success') {
        lotal_advance_order(
            $code,
            'paid',
            sprintf('Webhook: Paystack confirmed %s via %s.', lotal_money((int) ($data['amount'] ?? 0)), (string) ($data['channel'] ?? 'checkout'))
        );
        return ['ok' => true, 'order' => $code, 'status' => 'paid'];
    }

    if ($name === 'charge.failed' || ($data['status'] ?? '') === 'failed') {
        lotal_exec(
            'INSERT INTO order_events (order_id, status, title, note)
             SELECT id, ?, ?, ? FROM orders WHERE code = ?',
            [
                'placed',
                'Payment failed',
                'Webhook: Paystack reported a failed payment attempt. The unit is still reserved for 24 hours.',
                $code,
            ]
        );
        return ['ok' => true, 'order' => $code, 'status' => 'failed'];
    }

    return ['ok' => true, 'ignored' => $name];
}
