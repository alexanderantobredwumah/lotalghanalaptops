<?php
/**
 * LOTAL GHANA — cart, checkout, payment, order, tracking, account and auth pages.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* cart                                                                */
/* ------------------------------------------------------------------ */

function lotal_page_cart(): string
{
    $lines = lotal_cart_lines();
    $totals = lotal_cart_totals((string) (lotal_current_user()['city'] ?? 'Accra'));

    if ($lines === []) {
        $body = '<section class="wrap slim"><div class="plate-flat empty">'
            . '<p class="ico-big">' . lotal_icon('cart') . '</p>'
            . '<p class="display">Your cart is empty.</p>'
            . '<p class="muted">Add a laptop from the inventory and it will show up here. Stock is one-of-one, so do not wait too long.</p>'
            . '<a class="btn btn-primary" href="' . lotal_url('/shop') . '">Browse inventory ' . lotal_icon('arrow') . '</a>'
            . '</div></section>';
        return lotal_layout('Your Cart', $body);
    }

    $rows = '';
    foreach ($lines as $line) {
        $product = $line['product'];
        $rows .= '<li class="cart-row">'
            . '<a href="' . lotal_url('/product/' . rawurlencode((string) $product['slug'])) . '">'
            . '<img src="' . lotal_e($product['image']) . '" alt=""></a>'
            . '<div class="cart-info"><a class="cart-name" href="' . lotal_url('/product/' . rawurlencode((string) $product['slug'])) . '">'
            . lotal_e($product['brand'] . ' ' . $product['name']) . '</a>'
            . '<p class="micro">Grade ' . lotal_e($product['grade']) . ' · ' . lotal_e($product['ram']) . ' · ' . lotal_e($product['storage']) . '</p>'
            . '<div class="cart-tools">'
            . '<form method="post" action="' . lotal_url('/cart') . '">' . lotal_csrf_field()
            . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
            . '<input type="hidden" name="qty" value="' . ((int) $line['qty'] - 1) . '">'
            . '<button class="qty-btn" name="action" value="cart_set" aria-label="Decrease">−</button></form>'
            . '<span class="tabular">' . str_pad((string) $line['qty'], 2, '0', STR_PAD_LEFT) . '</span>'
            . '<form method="post" action="' . lotal_url('/cart') . '">' . lotal_csrf_field()
            . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
            . '<input type="hidden" name="qty" value="' . ((int) $line['qty'] + 1) . '">'
            . '<button class="qty-btn" name="action" value="cart_set" aria-label="Increase">+</button></form>'
            . '<form method="post" action="' . lotal_url('/cart') . '">' . lotal_csrf_field()
            . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
            . '<input type="hidden" name="qty" value="0">'
            . '<button class="link-btn micro" name="action" value="cart_set">Remove</button></form>'
            . '</div></div>'
            . '<p class="tabular gold">' . lotal_money((int) $line['line_total'], true) . '</p></li>';
    }

    $user = lotal_current_user();
    $body = '<section class="wrap slim"><p class="micro micro-gold">Cart / review your units</p>'
        . '<h1 class="display page-title">Your cart.</h1>'
        . '<p class="lede">Items are reserved only once your order is placed — used stock is one-of-one and can sell out at any time.</p>'
        . '<div class="two-col cart-grid"><div><div class="result-bar"><p class="micro">' . count($lines)
        . (count($lines) === 1 ? ' unit' : ' units') . ' in cart</p>'
        . '<form method="post" action="' . lotal_url('/cart') . '">' . lotal_csrf_field()
        . '<button class="link-btn micro" name="action" value="cart_clear">Clear cart</button></form></div>'
        . '<ul class="cart-list">' . $rows . '</ul></div>'
        . '<aside class="plate summary"><p class="micro micro-gold">Order summary</p>'
        . '<dl class="totals"><div><dt class="micro">Subtotal</dt><dd class="tabular">' . lotal_money((int) $totals['subtotal']) . '</dd></div>'
        . '<div><dt class="micro">Delivery</dt><dd class="tabular muted">Calculated at checkout</dd></div>'
        . '<div class="grand"><dt class="micro">Estimated total</dt><dd class="tabular huge-gold">' . lotal_money((int) $totals['total']) . '</dd></div></dl>'
        . '<a class="btn btn-primary wide" href="' . lotal_url($user ? '/checkout' : '/login?next=%2Fcheckout') . '">'
        . ($user ? 'Proceed to checkout' : 'Sign in to checkout') . ' ' . lotal_icon('arrow') . '</a>'
        . '<ul class="ticks sm">'
        . '<li>' . lotal_icon('check') . '<span>' . (int) lotal_config()['warranty_days'] . '-day warranty included</span></li>'
        . '<li>' . lotal_icon('check') . '<span>7-day return window</span></li>'
        . '<li>' . lotal_icon('check') . '<span>Inspection report in the box</span></li></ul>'
        . '</aside></div></section>';

    return lotal_layout('Your Cart', $body);
}

/* ------------------------------------------------------------------ */
/* checkout                                                            */
/* ------------------------------------------------------------------ */

function lotal_page_checkout(array $user): string
{
    $lines = lotal_cart_lines();
    if ($lines === []) {
        lotal_flash('Your cart is empty — add a laptop before checking out.', 'warn');
        lotal_redirect('/shop');
    }

    $methods = [
        ['paystack', 'Paystack — card, bank or mobile money', 'Visa, Mastercard, Verve, MTN MoMo, Telecel Cash, AT Money', 'lock'],
        ['momo', 'MTN Mobile Money via Paystack', 'Approve the prompt on your phone', 'phone'],
        ['transfer', 'Bank transfer', 'We send our account details after checkout', 'box'],
        ['delivery', 'Pay on delivery', 'Cash to the rider — Accra & Tema only', 'truck'],
    ];

    $city = (string) ($user['city'] ?: 'Accra');
    $totals = lotal_cart_totals($city);

    $methodRows = '';
    foreach ($methods as $index => [$id, $label, $hint, $icon]) {
        $methodRows .= '<label class="pay-opt"><input type="radio" name="payment_method" value="' . lotal_e($id) . '"'
            . ($index === 0 ? ' checked' : '') . '>'
            . '<span class="pay-ico">' . lotal_icon($icon) . '</span>'
            . '<span class="pay-txt"><b>' . lotal_e($label) . '</b><i class="micro">' . lotal_e($hint) . '</i></span>'
            . '<span class="radio"></span></label>';
    }

    $itemRows = '';
    foreach ($lines as $line) {
        $itemRows .= '<li><img src="' . lotal_e($line['product']['image']) . '" alt="">'
            . '<span class="flex-1"><b>' . lotal_e($line['product']['brand'] . ' ' . $line['product']['name']) . '</b>'
            . '<i class="micro">Qty ' . (int) $line['qty'] . '</i></span>'
            . '<span class="tabular gold">' . lotal_money((int) $line['line_total'], true) . '</span></li>';
    }

    $cities = '';
    foreach (lotal_cities() as $option) {
        $cities .= '<option value="' . lotal_e($option) . '"' . ($option === $city ? ' selected' : '') . '>' . lotal_e($option) . '</option>';
    }

    $body = '<section class="wrap slim"><p class="micro micro-gold">Checkout / secure</p>'
        . '<h1 class="display page-title">Almost yours.</h1>'
        . '<div class="two-col"><div class="stack">'
        . '<form id="checkout" method="post" action="' . lotal_url('/checkout') . '">' . lotal_csrf_field()
        . '<input type="hidden" name="action" value="place_order">'
        . '<section class="plate-flat pad"><p class="micro micro-gold">01 / Delivery details</p>'
        . '<div class="form-grid">'
        . '<label><span class="micro">Full name</span><input class="field" name="fullName" required value="' . lotal_e($user['full_name']) . '"></label>'
        . '<label><span class="micro">Phone number</span><input class="field" name="phone" required value="' . lotal_e($user['phone']) . '"></label>'
        . '<label><span class="micro">Email address</span><input class="field" type="email" name="email" required value="' . lotal_e($user['email']) . '"></label>'
        . '<label><span class="micro">City / region</span><select class="field" name="city" data-city>' . $cities . '</select></label>'
        . '<label class="span-2"><span class="micro">Delivery address &amp; landmark</span>'
        . '<textarea class="field" name="address" rows="3" required placeholder="House number, street, area — plus a landmark the rider can use"></textarea></label>'
        . '<label class="span-2"><span class="micro">Notes for the rider (optional)</span>'
        . '<textarea class="field" name="notes" rows="2" placeholder="Call before arrival, gate code, best delivery time…"></textarea></label>'
        . '</div></section>'
        . '<section class="plate-flat pad"><p class="micro micro-gold">02 / Payment method</p>'
        . '<div class="pay-list">' . $methodRows . '</div>'
        . '<p class="micro">Card and mobile money payments are handled by Paystack over an encrypted connection. '
        . 'Your PIN and card number never touch a LOTAL GHANA server.</p></section>'
        . '</form></div>'
        . '<aside class="plate pad summary"><p class="micro micro-gold">03 / Order summary</p>'
        . '<ul class="mini-items">' . $itemRows . '</ul>'
        . '<dl class="totals"><div><dt class="micro">Subtotal</dt><dd class="tabular" data-subtotal>' . lotal_money((int) $totals['subtotal']) . '</dd></div>'
        . '<div><dt class="micro">Delivery — <span data-city-label>' . lotal_e($city) . '</span></dt>'
        . '<dd class="tabular" data-delivery>' . lotal_money((int) $totals['delivery']) . '</dd></div>'
        . '<div class="grand"><dt class="micro">Total to pay</dt>'
        . '<dd class="tabular huge-gold" data-total>' . lotal_money((int) $totals['total']) . '</dd></div></dl>'
        . '<button class="btn btn-primary wide" type="submit" form="checkout">Place order ' . lotal_icon('arrow') . '</button>'
        . '<p class="micro">You will receive a unique tracking code immediately after checkout. Keep it safe — it works without an account.</p>'
        . '</aside></div></section>';

    return lotal_layout('Checkout', $body);
}

/* ------------------------------------------------------------------ */
/* payment                                                             */
/* ------------------------------------------------------------------ */

function lotal_page_pay(array $order, array $items): string
{
    $config = lotal_config();
    $gateway = $config['paystack'];
    $paid = $order['status'] !== 'placed';

    $rows = '';
    foreach ($items as $item) {
        $rows .= '<li><img src="' . lotal_e($item['image']) . '" alt="">'
            . '<span class="flex-1">' . lotal_e($item['title']) . '</span>'
            . '<span class="micro">×' . (int) $item['qty'] . '</span>'
            . '<span class="tabular gold">' . lotal_money((int) $item['unit_price'] * (int) $item['qty'], true) . '</span></li>';
    }

    $panel = $paid
        ? '<div class="plate pad"><p class="micro micro-gold">Status</p>'
            . '<p class="display panel-title">Paid &amp; queued</p>'
            . '<p class="muted">Reference <span class="tabular">' . lotal_e($order['payment_reference'] ?: '—') . '</span></p>'
            . '<a class="btn btn-primary wide mt" href="' . lotal_url('/track?code=' . rawurlencode((string) $order['code'])) . '">Track this order ' . lotal_icon('arrow') . '</a>'
            . '<a class="btn btn-ghost wide mt" href="' . lotal_url('/shop') . '">Continue shopping</a></div>'
        : ($gateway['enabled']
            ? '<div class="plate pad"><div class="flex-between"><p class="micro micro-gold">Paystack checkout</p>'
                . '<span class="pill ' . ($gateway['mode'] === 'live' ? 'gold' : '') . '">'
                . strtoupper($gateway['mode'] === 'live' ? 'LIVE KEYS' : 'TEST MODE') . '</span></div>'
                . '<p class="muted">Amount charged to your wallet or card: <b class="tabular white">'
                . lotal_money((int) $order['total']) . '</b></p>'
                . '<div class="paystack-box" data-paystack'
                . ' data-code="' . lotal_e($order['code']) . '"'
                . ' data-email="' . lotal_e($order['email']) . '"'
                . ' data-amount="' . (int) $order['total'] . '"'
                . ' data-name="' . lotal_e($order['full_name']) . '"'
                . ' data-currency="' . lotal_e($gateway['currency']) . '"'
                . ' data-csrf="' . lotal_e(lotal_csrf_token()) . '">'
                . '<button class="btn btn-primary wide" type="button" data-pay-now>' . lotal_icon('lock') . ' Pay with Paystack</button>'
                . '<p class="micro">Card · bank transfer · MTN MoMo · Telecel Cash · AT Money · QR — secured by Paystack</p>'
                . '<p class="err" data-pay-error hidden></p></div>'
                . '<p class="micro mt">Prefer cash? <a href="' . lotal_url('/order/' . rawurlencode((string) $order['code'])) . '">Keep this order unpaid</a> and pay the rider inside Accra and Tema.</p>'
                . '</div>'
            : '<div class="plate pad"><p class="micro micro-gold">Payment setup</p>'
                . '<p class="display panel-title">Paystack keys not added yet</p>'
                . '<p class="muted">Set <b class="tabular white">PAYSTACK_SECRET_KEY</b> and <b class="tabular white">PAYSTACK_PUBLIC_KEY</b> '
                . 'in <code>.env</code>, then redeploy. Your order stays reserved meanwhile.</p>'
                . '<div class="bank-box"><p class="micro">Or pay by bank transfer</p>'
                . '<p class="tabular white">' . lotal_e($gateway['bank_name']) . '</p>'
                . '<p class="tabular">' . lotal_e($gateway['bank_account']) . ' · ' . lotal_e($gateway['bank_holder']) . '</p>'
                . '<p class="micro">Use your tracking code ' . lotal_e($order['code']) . ' as the transfer reference.</p></div>'
                . '<a class="btn btn-primary wide mt" href="' . lotal_url('/order/' . rawurlencode((string) $order['code'])) . '">Continue to order ' . lotal_icon('arrow') . '</a></div>');

    $body = '<section class="page-head auth-head">' . lotal_backdrop(lotal_asset('img/auth.jpg'), 'auth-veil')
        . '<div class="wrap two-col">'
        . '<div><p class="micro micro-gold">Payment / secure checkout</p>'
        . '<h1 class="display page-title">' . ($paid ? 'Payment already received.' : 'Complete your payment.') . '</h1>'
        . '<p class="lede">' . ($paid
            ? 'Order <span class="tabular white">' . lotal_e($order['code']) . '</span> is confirmed and already moving through our workshop.'
            : 'Pay once and your unit is reserved immediately. Paystack settles the funds to our account and we release the laptop for packing the same working day.') . '</p>'
        . '<div class="plate pad glass-deep"><div class="result-bar"><span class="micro">Order</span>'
        . '<span class="tabular white">' . lotal_e($order['code']) . '</span></div>'
        . '<ul class="mini-items">' . $rows . '</ul>'
        . '<dl class="totals"><div><dt class="micro">Subtotal</dt><dd class="tabular">' . lotal_money((int) $order['subtotal']) . '</dd></div>'
        . '<div><dt class="micro">Delivery — ' . lotal_e($order['city']) . '</dt><dd class="tabular">' . lotal_money((int) $order['delivery']) . '</dd></div>'
        . '<div class="grand"><dt class="micro">Amount due</dt><dd class="tabular huge-gold">' . lotal_money((int) $order['total']) . '</dd></div></dl>'
        . '<p class="micro">Method on file: ' . lotal_e(lotal_payment_label((string) $order['payment_method'])) . ' · placed ' . lotal_date((string) $order['created_at']) . '</p>'
        . '</div></div>'
        . '<div>' . $panel . '</div>'
        . '</div></section>';

    return lotal_layout('Payment — ' . $order['code'], $body);
}

/* ------------------------------------------------------------------ */
/* order confirmation                                                    */
/* ------------------------------------------------------------------ */

function lotal_page_order(array $order, bool $justPaid = false): string
{
    $statuses = lotal_statuses();
    $activeIndex = lotal_status_index((string) $order['status']);
    $totalSteps = count($statuses);

    $rail = '';
    foreach ($statuses as $key => $meta) {
        $done = $activeIndex >= lotal_status_index($key);
        $eventTime = '';
        foreach ($order['events'] as $event) {
            if ($event['status'] === $key) {
                $eventTime = lotal_date((string) $event['created_at']);
            }
        }
        $rail .= '<li class="' . ($done ? 'done' : '') . ($key === $order['status'] ? ' current' : '') . '">'
            . '<span class="node">' . ($done ? lotal_icon('box') : '<i class="tabular">' . str_pad((string) (lotal_status_index($key) + 1), 2, '0', STR_PAD_LEFT) . '</i>') . '</span>'
            . '<div><p class="rail-label">' . lotal_e($meta['label']) . '</p>'
            . '<p class="micro">' . lotal_e($done ? ($eventTime ?: '—') : 'Pending') . '</p></div></li>';
    }

    $itemRows = '';
    foreach ($order['items'] as $item) {
        $itemRows .= '<li><img src="' . lotal_e($item['image']) . '" alt="">'
            . '<span class="flex-1">' . lotal_e($item['title']) . '</span>'
            . '<span class="micro">×' . (int) $item['qty'] . '</span>'
            . '<span class="tabular gold">' . lotal_money((int) $item['unit_price'] * (int) $item['qty'], true) . '</span></li>';
    }

    $awaitingPayment = $order['status'] === 'placed' && lotal_needs_payment((string) $order['payment_method']);
    $payStrip = '';
    if ($awaitingPayment) {
        $enabled = lotal_paystack_enabled();
        $payStrip = '<div class="plate pad mt"><div class="flex-between wrap-any">'
            . '<div><p class="micro micro-gold">Payment outstanding</p>'
            . '<p class="tabular huge-gold">' . lotal_money((int) $order['total']) . '</p></div>'
            . '<a class="btn btn-ghost sm" href="' . lotal_url('/pay/' . rawurlencode((string) $order['code'])) . '">Payment page ' . lotal_icon('arrow') . '</a></div>'
            . '<p class="muted">' . ($enabled
                ? 'Pay now with card, bank transfer or mobile money and we start packing the same day.'
                : 'Add your Paystack keys to enable online payment, or pay the rider on delivery.') . '</p>'
            . ($enabled ? '<div class="paystack-box" data-paystack data-code="' . lotal_e($order['code']) . '"'
                . ' data-email="' . lotal_e($order['email']) . '" data-amount="' . (int) $order['total'] . '"'
                . ' data-name="' . lotal_e($order['full_name']) . '" data-currency="' . lotal_e(lotal_config()['paystack']['currency']) . '"'
                . ' data-csrf="' . lotal_e(lotal_csrf_token()) . '">'
                . '<button class="btn btn-primary sm" type="button" data-pay-now>' . lotal_icon('lock') . ' Pay with Paystack</button>'
                . '<p class="err" data-pay-error hidden></p></div>' : '')
            . '</div>';
    }

    $eventLog = '';
    foreach (array_reverse($order['events']) as $event) {
        $eventLog .= '<li><p class="white">' . lotal_e($event['title']) . '</p>'
            . ($event['note'] !== '' ? '<p class="muted">' . lotal_e($event['note']) . '</p>' : '')
            . '<p class="micro">' . lotal_date((string) $event['created_at']) . ' · ' . lotal_rel((string) $event['created_at']) . '</p></li>';
    }

    $body = '<section class="page-head">' . lotal_backdrop(lotal_asset('img/hero.jpg'), 'page-veil')
        . '<div class="wrap">'
        . '<p class="micro micro-gold">' . ($justPaid ? 'Payment confirmed / thank you' : 'Order confirmed / thank you') . '</p>'
        . '<h1 class="display page-title">Your order is in.</h1>'
        . '<p class="lede">We have received your order for <span class="white">' . lotal_e($order['full_name']) . '</span> in '
        . lotal_e($order['city']) . '. Your unique tracking code is below — keep it safe, it is how you follow your laptop '
        . 'from our bench to your door.</p>'
        . '<div class="plate grain code-plate"><div><p class="micro micro-gold">Tracking code</p>'
        . '<p class="tabular code-big">' . lotal_e($order['code']) . '</p>'
        . '<p class="micro">Placed ' . lotal_date((string) $order['created_at']) . ' · Payment: ' . lotal_e(lotal_payment_label((string) $order['payment_method'])) . '</p></div>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . lotal_url('/track?code=' . rawurlencode((string) $order['code'])) . '">Track this order ' . lotal_icon('arrow') . '</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/shop') . '">Continue shopping</a></div></div>'
        . '</div></section>'
        . '<section class="wrap"><div class="rail-wrap"><p class="micro micro-gold">Live status — step '
        . ($activeIndex + 1) . ' of ' . $totalSteps . '</p><ol class="rail">' . $rail . '</ol></div>'
        . '<div class="two-col mt">'
        . '<div class="plate-flat pad">' . $payStrip
        . '<p class="micro micro-gold">Delivering to</p>'
        . '<p class="white mt-sm">' . lotal_e($order['full_name']) . '</p>'
        . '<p class="muted">' . lotal_e($order['address']) . '<br>' . lotal_e($order['city']) . ', Ghana</p>'
        . ($order['notes'] !== '' ? '<p class="micro mt-sm">Rider note: ' . lotal_e($order['notes']) . '</p>' : '')
        . '<hr class="rule-h"><p class="micro micro-gold">Receipt</p>'
        . '<ul class="mini-items">' . $itemRows . '</ul>'
        . '<dl class="totals"><div><dt class="micro">Subtotal</dt><dd class="tabular">' . lotal_money((int) $order['subtotal']) . '</dd></div>'
        . '<div><dt class="micro">Delivery</dt><dd class="tabular">' . lotal_money((int) $order['delivery']) . '</dd></div>'
        . '<div class="grand"><dt class="micro">' . ($awaitingPayment ? 'Total due' : 'Total paid') . '</dt>'
        . '<dd class="tabular huge-gold">' . lotal_money((int) $order['total']) . '</dd></div></dl></div>'
        . '<div class="plate-flat pad"><p class="micro micro-gold">Event log</p><ol class="events">' . $eventLog . '</ol>'
        . '<div class="note-box mt"><p class="micro micro-gold">Need help with this order?</p>'
        . '<p>Call <span class="tabular white">' . lotal_e(lotal_config()['support']['phone']) . '</span> with your tracking code and we will chase the rider for you.</p></div>'
        . '</div></div></section>';

    return lotal_layout('Order ' . $order['code'], $body);
}

/* ------------------------------------------------------------------ */
/* tracking                                                            */
/* ------------------------------------------------------------------ */

function lotal_page_track(?array $order, string $requested, ?string $error): string
{
    $result = '';
    if ($order) {
        $statuses = lotal_statuses();
        $activeIndex = lotal_status_index((string) $order['status']);
        $rail = '';
        foreach ($statuses as $key => $meta) {
            $done = $activeIndex >= lotal_status_index($key);
            $time = '';
            foreach ($order['events'] as $event) {
                if ($event['status'] === $key) {
                    $time = lotal_date((string) $event['created_at']);
                }
            }
            $rail .= '<li class="' . ($done ? 'done' : '') . ($key === $order['status'] ? ' current' : '') . '">'
                . '<span class="node">' . ($done ? lotal_icon('box') : '<i class="tabular">' . str_pad((string) (lotal_status_index($key) + 1), 2, '0', STR_PAD_LEFT) . '</i>') . '</span>'
                . '<div><p class="rail-label">' . lotal_e($meta['label']) . '</p>'
                . '<p class="micro">' . lotal_e($done ? ($time ?: '—') : 'Pending') . '</p></div></li>';
        }

        $items = '';
        foreach ($order['items'] as $item) {
            $items .= '<li><img src="' . lotal_e($item['image']) . '" alt="">'
                . '<span class="flex-1"><b>' . lotal_e($item['title']) . '</b><i class="micro">Qty ' . (int) $item['qty'] . '</i></span>'
                . '<span class="tabular gold">' . lotal_money((int) $item['unit_price'] * (int) $item['qty'], true) . '</span></li>';
        }

        $events = '';
        foreach (array_reverse($order['events']) as $event) {
            $events .= '<li><p class="white">' . lotal_e($event['title']) . '</p>'
                . ($event['note'] !== '' ? '<p class="muted">' . lotal_e($event['note']) . '</p>' : '')
                . '<p class="micro">' . lotal_date((string) $event['created_at']) . ' · ' . lotal_rel((string) $event['created_at']) . '</p></li>';
        }

        $progress = (int) round((($activeIndex + 1) / count($statuses)) * 100);
        $result = '<div class="plate grain mt result-card"><div class="scan"></div>'
            . '<div class="result-head"><div><p class="micro micro-gold">Tracking code</p>'
            . '<p class="tabular code-big">' . lotal_e($order['code']) . '</p>'
            . '<p class="micro">Placed ' . lotal_date((string) $order['created_at']) . ' · ' . lotal_e($order['city'])
            . ' · ' . lotal_e(lotal_payment_label((string) $order['payment_method'])) . '</p></div>'
            . '<div class="result-status"><p class="micro">Current status</p>'
            . '<p class="display status-name">' . lotal_e(lotal_status_label((string) $order['status'], 'short')) . '</p>'
            . '<p class="micro"><i class="dot"></i> Updated ' . lotal_date((string) $order['updated_at']) . '</p></div></div>'
            . '<div class="progress"><i style="width:' . $progress . '%"></i></div>'
            . '<ol class="rail">' . $rail . '</ol></div>'
            . '<div class="two-col mt">'
            . '<div class="plate-flat pad"><p class="micro micro-gold">Shipment contents</p>'
            . '<ul class="mini-items">' . $items . '</ul>'
            . '<dl class="totals"><div><dt class="micro">Subtotal</dt><dd class="tabular">' . lotal_money((int) $order['subtotal']) . '</dd></div>'
            . '<div><dt class="micro">Delivery</dt><dd class="tabular">' . lotal_money((int) $order['delivery']) . '</dd></div>'
            . '<div class="grand"><dt class="micro">Total</dt><dd class="tabular huge-gold">' . lotal_money((int) $order['total']) . '</dd></div></dl></div>'
            . '<div class="stack">'
            . '<div class="plate-flat pad"><p class="micro">' . lotal_icon('pin') . ' Delivering to</p>'
            . '<p class="white mt-sm">' . lotal_e($order['full_name']) . '</p>'
            . '<p class="muted">' . lotal_e($order['address']) . '<br>' . lotal_e($order['city']) . ', Ghana</p></div>'
            . '<div class="plate-flat pad"><p class="micro micro-gold">Event log</p><ol class="events">' . $events . '</ol></div>'
            . '<div class="note-box pad"><p class="micro micro-gold">Need help?</p>'
            . '<p>Call <span class="tabular white">' . lotal_e(lotal_config()['support']['phone']) . '</span> quoting '
            . lotal_e($order['code']) . '.</p></div></div></div>';
    } elseif ($requested !== '') {
        $result = '<div class="banner banner-err mt">' . lotal_e($error ?? 'We could not find that tracking code.') . '</div>';
    } else {
        $steps = [
            ['01', 'Find your code', 'It is on your receipt and starts with LT-.'],
            ['02', 'Enter it above', 'Codes are 11 characters: LT-XXXX-XXXX-X.'],
            ['03', 'Follow the rail', 'Watch it move from our bench to your door.'],
        ];
        $cards = '';
        foreach ($steps as [$number, $title, $text]) {
            $cards .= '<div class="plate-flat pad"><p class="tabular blue">' . $number . '</p>'
                . '<p class="display card-title">' . lotal_e($title) . '</p>'
                . '<p class="muted">' . lotal_e($text) . '</p></div>';
        }
        $result = '<div class="grid-3 mt">' . $cards . '</div>';
    }

    $body = '<section class="page-head">' . lotal_backdrop(lotal_asset('img/delivery.jpg'), 'page-veil')
        . '<div class="wrap">'
        . '<p class="micro micro-gold">Order tracking / live status</p>'
        . '<h1 class="display page-title">Where is my laptop?</h1>'
        . '<p class="lede">Every LOTAL GHANA order is issued a unique tracking code the moment it is placed. Type it below to see '
        . 'the exact stage your machine has reached — no account needed.</p>'
        . '<form class="glass track-form" method="get" action="' . lotal_url('/track') . '">'
        . '<label class="track-input">' . lotal_icon('search')
        . '<span class="micro">Tracking code</span>'
        . '<input name="code" data-code-input value="' . lotal_e($requested) . '" placeholder="LT-4KZP-92QF-7M" maxlength="15" autocomplete="off" spellcheck="false" aria-label="Tracking code"></label>'
        . '<button class="btn btn-primary" type="submit">' . lotal_icon('truck') . ' Track order</button>'
        . '<p class="micro">Your tracking code is printed on your receipt and sent in your order confirmation — it always starts with LT-. '
        . 'Tracking works without an account.</p></form>'
        . $result
        . '</div></section>';

    return lotal_layout('Track Your Order', $body);
}

/* ------------------------------------------------------------------ */
/* account                                                             */
/* ------------------------------------------------------------------ */

function lotal_page_account(array $user, array $orders): string
{
    $initials = '';
    foreach (explode(' ', (string) $user['full_name']) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    $rows = '';
    foreach ($orders as $order) {
        $statuses = lotal_statuses();
        $activeIndex = lotal_status_index((string) $order['status']);
        $progress = (int) round((($activeIndex + 1) / count($statuses)) * 100);
        $items = '';
        foreach ($order['items'] as $item) {
            $items .= '<span class="item-pill"><img src="' . lotal_e($item['image']) . '" alt="">'
                . '<span class="micro">' . lotal_e($item['title']) . '</span></span>';
        }
        $rows .= '<li class="plate-flat pad"><div class="flex-between wrap-any">'
            . '<div><p class="micro">Tracking code</p>'
            . '<a class="tabular code-mid" href="' . lotal_url('/track?code=' . rawurlencode((string) $order['code'])) . '">' . lotal_e($order['code']) . '</a>'
            . '<p class="micro">Placed ' . lotal_date((string) $order['created_at']) . ' · ' . lotal_e($order['city']) . '</p></div>'
            . '<div class="right"><p class="tabular gold">' . lotal_money((int) $order['total'], true) . '</p>'
            . '<p class="micro blue">' . lotal_e(lotal_status_label((string) $order['status'], 'short')) . '</p></div></div>'
            . '<div class="progress sm"><i style="width:' . $progress . '%"></i></div>'
            . '<div class="flex-between wrap-any mt-sm"><div class="pill-row">' . $items . '</div>'
            . '<a class="btn btn-ghost sm" href="' . lotal_url('/order/' . rawurlencode((string) $order['code'])) . '">View order ' . lotal_icon('arrow') . '</a></div></li>';
    }

    if ($orders === []) {
        $rows = '<li class="plate-flat empty"><p class="display">No orders yet.</p>'
            . '<p class="muted">When you place an order it appears here with its tracking code, invoice and warranty record.</p>'
            . '<a class="btn btn-primary" href="' . lotal_url('/shop') . '">Browse inventory ' . lotal_icon('arrow') . '</a></li>';
    }

    $warranty = (int) lotal_config()['warranty_days'];
    $body = '<section class="wrap slim"><div class="flex-between wrap-any">'
        . '<div><p class="micro micro-gold">Account / ' . lotal_e($user['email']) . '</p>'
        . '<h1 class="display page-title">Hello, ' . lotal_e(explode(' ', (string) $user['full_name'])[0]) . '.</h1></div>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . lotal_url('/shop') . '">Shop laptops ' . lotal_icon('arrow') . '</a>'
        . '<form method="post" action="' . lotal_url('/account') . '">' . lotal_csrf_field()
        . '<button class="btn btn-ghost" name="action" value="logout" type="submit">Sign out</button></form></div></div>'
        . '<div class="two-col mt"><div class="stack">'
        . '<div class="plate pad"><div class="avatar-row"><span class="avatar lg">' . lotal_e(mb_substr($initials, 0, 2)) . '</span>'
        . '<div><p class="white big">' . lotal_e($user['full_name']) . '</p>'
        . '<p class="micro">Customer since ' . lotal_date((string) $user['created_at'], false) . '</p></div></div>'
        . '<dl class="profile"><div><dt class="micro">Email</dt><dd>' . lotal_e($user['email']) . '</dd></div>'
        . '<div><dt class="micro">Phone</dt><dd>' . lotal_e($user['phone'] ?: 'Not provided') . '</dd></div>'
        . '<div><dt class="micro">City</dt><dd>' . lotal_e($user['city'] ?: 'Not provided') . '</dd></div>'
        . '<div><dt class="micro">Account type</dt><dd>' . ($user['role'] === 'admin' ? 'Administrator' : 'Customer') . '</dd></div></dl></div>'
        . '<div class="note-box pad"><p class="micro micro-gold">Warranty status</p>'
        . '<p class="muted">Every unit you buy carries a ' . $warranty . '-day parts and labour warranty and a 7-day return window, '
        . 'both starting from the delivery date recorded on your tracking code.</p>'
        . '<a class="btn btn-ghost wide mt-sm" href="' . lotal_url('/#warranty') . '">Read the warranty policy</a></div>'
        . '</div><div><div class="flex-between"><p class="micro micro-gold">Your orders</p><span class="rule"></span>'
        . '<p class="micro">' . str_pad((string) count($orders), 2, '0', STR_PAD_LEFT) . ' total</p></div>'
        . '<ul class="stack">' . $rows . '</ul></div></div></section>';

    return lotal_layout('My Account', $body);
}

/* ------------------------------------------------------------------ */
/* auth screens                                                        */
/* ------------------------------------------------------------------ */

function lotal_page_auth(string $mode, string $next = '', string $token = ''): string
{
    $config = lotal_config();
    $copy = [
        'login' => [
            'eyebrow' => 'Account / sign in',
            'title'   => 'Sign in to buy and track.',
            'lede'    => 'Purchases require a LOTAL GHANA account so we can attach your warranty, your invoice and your tracking code to your name.',
        ],
        'register' => [
            'eyebrow' => 'Account / register',
            'title'   => 'Create your buying account.',
            'lede'    => 'One account for checkout, order tracking, invoices and warranty claims. We only ask for what we need to deliver your laptop and reach you if there is an issue.',
        ],
        'forgot' => [
            'eyebrow' => 'Account / password recovery',
            'title'   => 'Forgot your password?',
            'lede'    => 'Enter the email address on your account and we will issue a secure link that lets you choose a new password. The link expires after one hour.',
        ],
        'reset' => [
            'eyebrow' => 'Account / new password',
            'title'   => 'Choose a new password.',
            'lede'    => 'Use at least eight characters and mix letters with numbers so your account — and your warranty records — stay protected.',
        ],
    ][$mode];

    $side = '';
    if ($mode === 'login') {
        $notes = [
            (int) $config['warranty_days'] . '-day warranty on every unit',
            'Track your order with a unique code',
            'Saved delivery details for faster checkout',
            'Priority support from our Osu workshop',
        ];
        foreach ($notes as $index => $note) {
            $side .= '<li><span class="tabular blue">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span><span>' . lotal_e($note) . '</span></li>';
        }
        $side = '<ul class="side-list">' . $side . '</ul>';
    } elseif ($mode === 'register') {
        foreach ([[lotal_icon('clock') . '1 min', 'to register'], [lotal_money(0, true), 'account fee'], [(int) $config['warranty_days'] . ' days', 'warranty cover'], ['16 cities', 'we deliver to']] as [$value, $label]) {
            $side .= '<div class="plate-flat pad sm"><p class="tabular white big">' . $value . '</p><p class="micro">' . lotal_e($label) . '</p></div>';
        }
        $side = '<div class="side-grid">' . $side . '</div>';
    } else {
        $side = '<div class="plate-flat pad"><p class="micro micro-gold">Still stuck?</p>'
            . '<p class="muted">Call our support desk on <span class="tabular white">' . lotal_e($config['support']['phone']) . '</span> '
            . '(Mon–Sat, 8am–7pm) or walk into the workshop at ' . lotal_e($config['support']['address']) . '. '
            . 'We can recover your account after verifying your order details.</p></div>';
    }

    $fields = '';
    if ($mode === 'register') {
        $fields .= '<label class="span-2"><span class="micro">Full name</span>'
            . '<input class="field" name="fullName" required autocomplete="name" placeholder="Ama Serwaa Mensah"></label>';
    }
    if ($mode !== 'reset') {
        $fields .= '<label class="span-2"><span class="micro">Email address</span>'
            . '<input class="field" type="email" name="email" required autocomplete="email" placeholder="you@example.com"></label>';
    }
    if ($mode === 'register') {
        $cities = '';
        foreach (lotal_cities() as $city) {
            $cities .= '<option value="' . lotal_e($city) . '"' . ($city === 'Accra' ? ' selected' : '') . '>' . lotal_e($city) . '</option>';
        }
        $fields .= '<label><span class="micro">Phone number</span><input class="field" name="phone" required autocomplete="tel" placeholder="+233 55 190 4477"></label>'
            . '<label><span class="micro">City</span><select class="field" name="city">' . $cities . '</select></label>';
    }
    if ($mode !== 'forgot') {
        $fields .= '<label class="span-2"><span class="micro">' . ($mode === 'reset' ? 'New password' : 'Password') . '</span>'
            . '<input class="field" type="password" name="password" required minlength="8" autocomplete="'
            . ($mode === 'reset' ? 'new-password' : 'current-password') . '" placeholder="••••••••"></label>';
    }
    if ($mode === 'register') {
        $fields .= '<label class="span-2"><span class="micro">Confirm password</span>'
            . '<input class="field" type="password" name="confirm" required minlength="8" autocomplete="new-password" placeholder="••••••••"></label>'
            . '<label class="span-2 agree"><input type="checkbox" name="terms" value="1" required>'
            . '<span>I agree to the LOTAL GHANA terms of sale, the ' . (int) $config['warranty_days']
            . '-day warranty policy and the privacy notice.</span></label>';
    }
    if ($mode === 'reset') {
        $fields .= '<input type="hidden" name="token" value="' . lotal_e($token) . '">';
    }

    $action = [
        'login'    => 'login',
        'register' => 'register',
        'forgot'   => 'forgot',
        'reset'    => 'reset',
    ][$mode];

    $submit = [
        'login'    => 'Sign in',
        'register' => 'Create account',
        'forgot'   => 'Send reset link',
        'reset'    => 'Set new password',
    ][$mode];

    $alt = match ($mode) {
        'login'    => '<p>New to LOTAL GHANA? <a href="' . lotal_url('/register?next=' . rawurlencode($next)) . '">Create an account</a></p>',
        'register' => '<p>Already have an account? <a href="' . lotal_url('/login?next=' . rawurlencode($next)) . '">Sign in</a></p>',
        default    => '<p>Remembered it? <a href="' . lotal_url('/login') . '">Back to sign in</a></p>',
    };

    $body = '<section class="auth"><div class="backdrop">'
        . '<img src="' . lotal_asset('img/auth.jpg') . '" alt=""><span class="veil auth-veil"></span><span class="grain"></span></div>'
        . '<div class="wrap auth-grid">'
        . '<header class="auth-top">' . lotal_wordmark() . '<a class="micro back" href="' . lotal_url('/') . '">Back to store</a></header>'
        . '<div class="auth-cols">'
        . '<div><p class="micro micro-gold">' . lotal_e($copy['eyebrow']) . '</p>'
        . '<h1 class="display auth-title">' . lotal_e($copy['title']) . '</h1>'
        . '<p class="lede">' . lotal_e($copy['lede']) . '</p>' . $side . '</div>'
        . '<form class="glass auth-card" method="post" action="' . lotal_url('/' . $mode) . '" novalidate>'
        . lotal_csrf_field() . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="next" value="' . lotal_e($next) . '">'
        . '<p class="micro micro-gold">' . lotal_e(ucfirst($mode) . ' form') . '</p><hr class="rule-h">'
        . '<div class="form-grid">' . $fields . '</div>'
        . ($mode === 'login' ? '<a class="micro blue" href="' . lotal_url('/forgot-password') . '">Forgotten password?</a>' : '')
        . '<button class="btn btn-primary wide" type="submit">' . lotal_e($submit) . ' ' . lotal_icon('arrow') . '</button>'
        . '<div class="auth-alt">' . $alt . '</div>'
        . '</form></div>'
        . '<footer class="auth-foot"><p class="micro">' . lotal_icon('lock') . ' Secured with TLS · passwords hashed with PBKDF2-SHA256</p>'
        . '<a class="micro" href="' . lotal_url('/#warranty') . '">Warranty policy</a>'
        . '<a class="micro" href="' . lotal_url('/track') . '">Track an order</a></footer>'
        . '</div></section>';

    return lotal_layout(match ($mode) {
        'login' => 'Sign In',
        'register' => 'Create Account',
        'forgot' => 'Forgotten Password',
        default => 'Set New Password',
    }, $body, ['chromeless' => true, 'body_class' => 'auth-body']);
}

function lotal_page_404(): string
{
    $body = '<section class="auth"><div class="backdrop">'
        . '<img src="' . lotal_asset('img/hero.jpg') . '" alt=""><span class="veil auth-veil"></span><span class="grain"></span></div>'
        . '<div class="wrap">' . lotal_nav(lotal_current_user())
        . '<div class="nf"><p class="micro micro-gold">Error 404 / page not found</p>'
        . '<p class="tabular nf-code">404</p>'
        . '<h1 class="display">This one is off the bench.</h1>'
        . '<p class="lede">The page you asked for is not here — it may have been sold, renamed or mistyped. '
        . 'The inventory changes weekly, so try a search or head back to the store.</p>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . lotal_url('/shop') . '">' . lotal_icon('search') . ' Browse inventory</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/') . '">Back to home ' . lotal_icon('arrow') . '</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/track') . '">Track an order</a></div></div>'
        . lotal_footer() . '</div></section>';

    return lotal_layout('Page not found', $body, ['chromeless' => true]);
}
