<?php
/**
 * LOTAL GHANA — operations console (staff only).
 * Reachable at /admin, /admin.html and /admin.php — never linked from the storefront.
 */

declare(strict_types=1);

function lotal_page_admin_login(?string $error = null): string
{
    $body = '<section class="admin-login"><div class="backdrop">'
        . '<img src="' . lotal_asset('img/auth.jpg') . '" alt=""><span class="veil admin-veil"></span><span class="scanline"></span></div>'
        . '<form class="glass admin-card" method="post" action="' . lotal_url('/admin') . '">' . lotal_csrf_field()
        . '<input type="hidden" name="action" value="admin_login">'
        . '<div class="admin-card-top">'
        . '<span class="admin-badge">' . lotal_icon('shield') . '</span>'
        . '<div><p class="micro micro-gold">Restricted / staff only</p>'
        . '<h1 class="display">Operations console</h1></div></div>'
        . '<label><span class="micro">Administrator email</span>'
        . '<input class="field" type="email" name="email" required autocomplete="username" placeholder="' . lotal_e(lotal_config()['admin']['email']) . '"></label>'
        . '<label><span class="micro">Password</span>'
        . '<input class="field" type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></label>'
        . ($error !== null ? '<p class="banner banner-err">' . lotal_e($error) . '</p>' : '')
        . '<button class="btn btn-primary wide" type="submit">' . lotal_icon('lock') . ' Sign in to console</button>'
        . '<p class="micro">This console is not linked from the customer store. Access is logged and restricted to '
        . 'LOTAL GHANA operations staff. Credentials come from ADMIN_EMAIL / ADMIN_PASSWORD in the environment.</p>'
        . '</form></section>';

    return lotal_layout('Operations Console', $body, ['chromeless' => true, 'body_class' => 'admin-body']);
}

function lotal_page_admin(array $stats, array $orders, array $products, array $customers, string $tab, array $admin): string
{
    $config = lotal_config();
    $gateway = $config['paystack'];
    $mask = static fn (string $value): string => $value === '' ? 'not set'
        : substr($value, 0, 6) . str_repeat('•', 10) . substr($value, -4);

    $tabs = [
        'orders'    => ['Orders', count($orders), 'box'],
        'inventory' => ['Inventory', count($products), 'spark'],
        'customers' => ['Customers', count($customers), 'user'],
        'payments'  => ['Payments', 0, 'lock'],
    ];
    $tabLinks = '';
    foreach ($tabs as $key => [$label, $count, $icon]) {
        $tabLinks .= '<a class="chip-f ' . ($tab === $key ? 'on' : '') . '" href="' . lotal_url('/admin?tab=' . $key) . '">'
            . lotal_icon($icon) . ' ' . lotal_e($label) . '<span class="tabular">' . (int) $count . '</span></a>';
    }

    $statTiles = '';
    foreach ([
        ['Revenue to date', lotal_money((int) $stats['revenue'], true)],
        ['Orders', str_pad((string) $stats['orders'], 2, '0', STR_PAD_LEFT)],
        ['Units in stock', str_pad((string) $stats['products'], 2, '0', STR_PAD_LEFT)],
        ['Awaiting payment', str_pad((string) $stats['unpaid'], 2, '0', STR_PAD_LEFT)],
        ['Customers', str_pad((string) $stats['customers'], 2, '0', STR_PAD_LEFT)],
    ] as [$label, $value]) {
        $statTiles .= '<div class="plate-flat pad"><p class="micro">' . lotal_e($label) . '</p>'
            . '<p class="tabular stat-sm">' . lotal_e($value) . '</p></div>';
    }

    $panel = '';
    if ($tab === 'orders') {
        $rows = '';
        foreach ($orders as $order) {
            $options = '';
            foreach (lotal_statuses() as $key => $meta) {
                $options .= '<option value="' . lotal_e($key) . '"' . ($order['status'] === $key ? ' selected' : '') . '>'
                    . lotal_e($meta['label']) . '</option>';
            }
            $items = implode(', ', array_map(static fn (array $item): string => (string) $item['title'], (array) $order['items']));
            $rows .= '<tr><td class="tabular white">' . lotal_e($order['code']) . '</td>'
                . '<td><b>' . lotal_e($order['full_name']) . '</b><i class="micro">' . lotal_e($order['city'] . ' · ' . $order['phone']) . '</i></td>'
                . '<td class="muted small">' . lotal_e($items) . '</td>'
                . '<td class="tabular gold">' . lotal_money((int) $order['total'], true) . '</td>'
                . '<td class="tabular muted small">' . lotal_date((string) $order['created_at']) . '</td>'
                . '<td><form method="post" action="' . lotal_url('/admin') . '" class="inline">' . lotal_csrf_field()
                . '<input type="hidden" name="action" value="admin_status">'
                . '<input type="hidden" name="order_id" value="' . lotal_e($order['id']) . '">'
                . '<select class="field sm" name="status" onchange="this.form.submit()">' . $options . '</select>'
                . '</form></td></tr>';
        }
        $panel = $orders === []
            ? '<p class="muted pad">No orders yet. They appear here the moment a customer checks out.</p>'
            : '<table class="admin-table"><thead><tr><th>Tracking code</th><th>Customer</th><th>Items</th><th>Total</th>'
                . '<th>Placed</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    if ($tab === 'inventory') {
        $rows = '';
        foreach ($products as $product) {
            $rows .= '<tr><td><div class="prod-cell"><img src="' . lotal_e($product['image']) . '" alt="">'
                . '<div><b>' . lotal_e($product['name']) . '</b><i class="micro">' . lotal_e($product['brand']) . '</i></div></div></td>'
                . '<td class="tabular gold">' . lotal_e($product['grade']) . '</td>'
                . '<td class="tabular muted">' . (int) $product['battery_health'] . '%</td>'
                . '<td><form method="post" action="' . lotal_url('/admin') . '" class="inline">' . lotal_csrf_field()
                . '<input type="hidden" name="action" value="admin_product">'
                . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
                . '<input class="field sm tabular" name="price" inputmode="decimal" value="' . number_format((int) $product['price'] / 100, 2, '.', '') . '">'
                . '<input class="field xs tabular" name="stock" inputmode="numeric" value="' . (int) $product['stock'] . '">'
                . '<button class="btn btn-ghost sm" type="submit">Save</button></form></td></tr>';
        }
        $panel = '<table class="admin-table"><thead><tr><th>Unit</th><th>Grade</th><th>Battery</th>'
            . '<th>Price (GH₵) &amp; stock</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    if ($tab === 'customers') {
        $rows = '';
        foreach ($customers as $customer) {
            $rows .= '<tr><td><b>' . lotal_e($customer['full_name']) . '</b>'
                . '<i class="micro">' . ($customer['role'] === 'admin' ? 'Administrator' : 'Customer') . '</i></td>'
                . '<td class="tabular small">' . lotal_e($customer['email']) . '<i class="micro">' . lotal_e($customer['phone'] ?: '—') . '</i></td>'
                . '<td class="muted small">' . lotal_e($customer['city'] ?: '—') . '</td>'
                . '<td class="tabular">' . str_pad((string) (int) $customer['orders'], 2, '0', STR_PAD_LEFT) . '</td>'
                . '<td class="tabular gold">' . lotal_money((int) $customer['spent'], true) . '</td>'
                . '<td class="tabular muted small">' . lotal_date((string) $customer['created_at']) . '</td></tr>';
        }
        $panel = '<table class="admin-table"><thead><tr><th>Customer</th><th>Contact</th><th>City</th>'
            . '<th>Orders</th><th>Lifetime spend</th><th>Joined</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    if ($tab === 'payments') {
        $webhook = lotal_url('/paystack/webhook');
        $rows = [
            ['PAYSTACK_SECRET_KEY', $mask($gateway['secret_key']), 'Server — initialise &amp; verify payments, sign webhooks'],
            ['PAYSTACK_PUBLIC_KEY', $mask($gateway['public_key']), 'Browser — opens the Paystack inline checkout'],
            ['PAYSTACK_CURRENCY', $gateway['currency'], 'GHS for Ghana; amounts are billed in pesewas'],
            ['SITE_URL', $config['site_url'], 'Used to build the callback and webhook URLs'],
            ['PAYSTACK_BANK_NAME', $gateway['bank_name'] ?: 'not set', 'Shown as the offline payment fallback'],
            ['PAYSTACK_BANK_ACCOUNT', $gateway['bank_account'] ?: 'not set', 'Bank transfer account number'],
            ['WARRANTY_DAYS', (string) $config['warranty_days'], 'Store warranty length used across the site'],
            ['ADMIN_EMAIL', $config['admin']['email'], 'Console sign-in'],
        ];
        $rowsHtml = '';
        foreach ($rows as [$name, $value, $note]) {
            $rowsHtml .= '<div class="env-row"><div><b class="tabular">' . lotal_e($name) . '</b>'
                . '<i class="tabular gold">' . lotal_e($value) . '</i></div><p class="micro">' . $note . '</p></div>';
        }
        $panel = '<div class="two-col"><div class="plate-flat pad">'
            . '<div class="flex-between wrap-any"><p class="micro micro-gold">Paystack gateway</p>'
            . '<span class="pill ' . ($gateway['enabled'] ? ($gateway['mode'] === 'live' ? 'gold' : 'blue') : '') . '">'
            . ($gateway['enabled'] ? strtoupper($gateway['mode'] === 'live' ? 'LIVE' : 'TEST MODE') : 'NOT CONFIGURED') . '</span></div>'
            . '<div class="env-list">' . $rowsHtml . '</div>'
            . '<p class="micro mt">Keys live in the deployment environment only (Vercel → Settings → Environment Variables, '
            . 'or php/.env for self-hosting). They are never stored in the database or committed to git. '
            . 'Restart PHP after editing .env.</p></div>'
            . '<div class="stack">'
            . '<div class="plate-flat pad"><p class="micro micro-gold">Webhook</p>'
            . '<p class="muted">Paste this into Paystack → Settings → Integrations → Webhooks. It is the source of truth that moves an '
            . 'order from <b class="white">placed</b> to <b class="white">paid</b> even if the buyer closes the tab.</p>'
            . '<code class="code-block">' . lotal_e($webhook) . '</code>'
            . '<p class="micro">Events to subscribe to: charge.success, charge.failed</p></div>'
            . '<div class="plate-flat pad"><p class="micro micro-gold">Test it locally</p>'
            . '<code class="code-block">paystack event trigger charge.success --secret ' . lotal_e($gateway['secret_key'] === '' ? 'sk_test_xxx' : 'sk_test_…') . ' --url ' . lotal_e($webhook) . '</code>'
            . '<p class="muted">Or use the Paystack CLI in the project folder. The handler verifies the SHA-512 HMAC signature before touching any order.</p></div>'
            . '</div></div>';
    }

    $body = '<section class="admin"><div class="wrap">'
        . '<div class="flex-between wrap-any"><div><p class="micro micro-gold">Operations console / ' . lotal_e($config['site_name']) . '</p>'
        . '<h1 class="display page-title-sm">Good day, ' . lotal_e(explode(' ', (string) $admin['full_name'])[0]) . '.</h1>'
        . '<p class="muted">Manage orders, update tracking statuses, keep inventory accurate and check the payment gateway.</p></div>'
        . '<div class="btn-row"><a class="btn btn-ghost sm" href="' . lotal_url('/') . '">View store</a>'
        . '<form method="post" action="' . lotal_url('/admin') . '">' . lotal_csrf_field()
        . '<button class="btn btn-ghost sm" name="action" value="admin_logout" type="submit">Sign out</button></form></div></div>'
        . '<div class="stat-row">' . $statTiles . '</div>'
        . '<nav class="admin-tabs">' . $tabLinks . '</nav>'
        . '<div class="admin-panel">' . $panel . '</div>'
        . '</div></section>';

    return lotal_layout('Operations Console', $body, ['body_class' => 'admin-body']);
}
