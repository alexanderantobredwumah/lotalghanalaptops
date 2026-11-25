<?php
/**
 * LOTAL GHANA — storefront views (PHP templates).
 * Every function returns an HTML string; lotal_layout() wraps it in the shell.
 * Prices, warranty terms and copy are read from lotal_config() so the store
 * policy is changed in exactly one place (php/.env → WARRANTY_DAYS).
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* shared fragments                                                    */
/* ------------------------------------------------------------------ */

function lotal_icon(string $name): string
{
    $paths = [
        'cart'    => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1"/><circle cx="17" cy="20" r="1"/>',
        'user'    => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 4.4-3.2 8-8 9-4.8-1-8-4.6-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'truck'   => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
        'battery' => '<rect x="2" y="8" width="16" height="8" rx="2"/><path d="M21 11v2"/><path d="M5 11v2"/>',
        'arrow'   => '<path d="M5 12h13"/><path d="m13 6 6 6-6 6"/>',
        'check'   => '<path d="m5 13 4 4L19 7"/>',
        'pin'     => '<path d="M12 21s7-5.3 7-11a7 7 0 1 0-14 0c0 5.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>',
        'lock'    => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'box'     => '<path d="M3 8l9-5 9 5v8l-9 5-9-5V8Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'spark'   => '<path d="M12 3v5"/><path d="M12 16v5"/><path d="M3 12h5"/><path d="M16 12h5"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'phone'   => '<path d="M5 3h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function lotal_logo(int $size = 34): string
{
    return '<span class="mark" style="width:' . $size . 'px;height:' . $size . 'px">'
        . '<svg viewBox="0 0 48 48" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
        . '<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M7 3h25.2L45 15.8V41a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4Zm7.4 12.2a1 1 0 0 0-1 1v19.6a1 1 0 0 0 1 1h19.4a1 1 0 0 0 1-1v-6.1a1 1 0 0 0-1-1H17.4V16.2a1 1 0 0 0-1-1Z"/>'
        . '<path fill="#F5B21E" d="M33.4 4.1 35 6.2l2.5.3-1.9 1.7.5 2.5-2.2-1.3-2.2 1.3.5-2.5-1.9-1.7 2.5-.3 1.6-2.1Z"/>'
        . '</svg></span>';
}

function lotal_wordmark(): string
{
    return '<a class="brand" href="' . lotal_url('/') . '" aria-label="' . lotal_e(lotal_config()['site_name']) . ' home">'
        . lotal_logo(32)
        . '<span class="brand-txt"><b>' . lotal_e(strtoupper(lotal_config()['site_name'])) . '</b>'
        . '<i>GHANA</i></span></a>';
}

function lotal_nav(?array $user): string
{
    $links = [
        ['/shop', 'Inventory'],
        ['/track', 'Track Order'],
        ['/#how', 'How It Works'],
        ['/#warranty', 'Warranty'],
        ['/#contact', 'Contact'],
    ];
    $items = '';
    foreach ($links as [$href, $label]) {
        $items .= '<a class="nav-link" href="' . lotal_e($href) . '">' . lotal_e($label) . '</a>';
    }

    $account = $user
        ? '<a class="chip" href="' . lotal_url('/account') . '">' . lotal_icon('user')
            . '<span>' . lotal_e(explode(' ', (string) $user['full_name'])[0]) . '</span></a>'
        : '<a class="chip" href="' . lotal_url('/login') . '">' . lotal_icon('user') . '<span>Sign In</span></a>';

    return '<header class="site-head" id="siteHead">'
        . '<div class="wrap head-in">'
        . lotal_wordmark()
        . '<nav class="nav">' . $items . '</nav>'
        . '<div class="head-act">'
        . '<a class="chip icon-only" href="' . lotal_url('/shop') . '" aria-label="Search inventory">' . lotal_icon('search') . '</a>'
        . $account
        . '<a class="chip icon-only" href="' . lotal_url('/cart') . '" aria-label="Cart"><span class="bag">'
        . lotal_icon('cart') . '</span><em class="tabular" data-cart-count>' . (lotal_cart_count() ?: '') . '</em></a>'
        . '<button class="chip icon-only burger" data-burger aria-label="Open menu" aria-expanded="false">'
        . '<span></span><span></span><span></span></button>'
        . '</div></div>'
        . '<nav class="mobile-nav" data-mobile-nav>'
        . $items
        . '<a class="btn btn-primary" href="' . lotal_url($user ? '/account' : '/register') . '">'
        . ($user ? 'My Account' : 'Create Account') . '</a>'
        . '</nav></header>';
}

function lotal_marquee(array $items): string
{
    $loop = array_merge($items, $items);
    $row = '';
    foreach ($loop as $item) {
        $row .= '<span class="mq-item">' . lotal_e($item) . '</span><span class="mq-diamond"></span>';
    }
    return '<div class="marquee"><div class="mq-track">' . $row . '</div></div>';
}

function lotal_footer(): string
{
    $config = lotal_config();
    $warranty = (int) $config['warranty_days'];
    $columns = [
        'Shop' => [
            ['All laptops', '/shop'], ['Apple / MacBook', '/shop?brand=Apple'],
            ['Dell & HP', '/shop?brand=Dell'], ['Lenovo ThinkPad', '/shop?brand=Lenovo'],
            ['Acer & ASUS', '/shop?brand=Acer'],
        ],
        'Customer care' => [
            ['Track your order', '/track'], ['My account', '/account'],
            ['Warranty & returns', '/#warranty'], ['How grading works', '/#how'],
            ['Forgot password', '/forgot-password'],
        ],
        'Company' => [
            ['About LOTAL GHANA', '/#about'], ['Visit our workshop', '/#contact'],
            ['Bulk & corporate sales', '/#contact'], ['Trade-in programme', '/#contact'],
            ['Terms & privacy', '/#warranty'],
        ],
    ];

    $cols = '';
    foreach ($columns as $title => $links) {
        $rows = '';
        foreach ($links as [$label, $href]) {
            $rows .= '<li><a href="' . lotal_e($href) . '">' . lotal_e($label) . '</a></li>';
        }
        $cols .= '<div><p class="micro micro-gold">' . lotal_e($title) . '</p><ul class="foot-links">' . $rows . '</ul></div>';
    }

    $methods = '';
    foreach (['MoMo', 'Telecel', 'AT', 'VISA', 'Mastercard', 'Paystack'] as $method) {
        $methods .= '<span class="pay-tag">' . $method . '</span>';
    }

    return '<footer class="site-foot" id="contact">'
        . '<div class="foot-band">' . lotal_marquee([
            $warranty . '-DAY WARRANTY ON EVERY UNIT',
            '7-DAY RETURN, NO QUESTIONS',
            $config['diagnostic_points'] . '-POINT DIAGNOSTIC REPORT',
            'PAY ON DELIVERY IN ACCRA & KUMASI',
            'CARD, BANK & MOBILE MONEY BY PAYSTACK',
            'TESTED IN OUR OSU WORKSHOP',
        ]) . '</div>'
        . '<div class="wrap foot-grid">'
        . '<div>' . lotal_wordmark()
        . '<p class="foot-blurb">Graded used laptops, inspected on a real bench by real technicians. '
        . 'We publish the battery health, the cycle count and every cosmetic mark before you pay a cedi.</p>'
        . '<div class="foot-contact"><p class="tabular">' . lotal_e($config['support']['address']) . '</p>'
        . '<p class="tabular">' . lotal_e($config['support']['phone']) . ' · ' . lotal_e($config['support']['phone2']) . '</p>'
        . '<p class="tabular">' . lotal_e($config['support']['email']) . '</p>'
        . '<p class="micro">' . lotal_e($config['support']['hours']) . '</p></div>'
        . '<div class="pay-row">' . $methods . '</div></div>'
        . $cols
        . '</div>'
        . '<div class="foot-base"><div class="wrap foot-base-in">'
        . '<p class="tabular">© ' . date('Y') . ' ' . lotal_e($config['site_name']) . ' LTD · REG. CS-042851</p>'
        . '<p class="tabular">EVERY ORDER COMES WITH A TRACKING CODE · <a href="' . lotal_url('/track') . '">TRACK YOURS</a></p>'
        . '</div></div></footer>';
}

function lotal_product_card(array $product, int $index = 0): string
{
    $inStock = (int) $product['stock'] > 0;
    $saved = $product['old_price'] && $product['old_price'] > $product['price'];
    $specs = [
        ['CPU', explode('·', $product['processor'])[0]],
        ['RAM', $product['ram']],
        ['Storage', $product['storage']],
    ];
    $specRows = '';
    foreach ($specs as [$label, $value]) {
        $specRows .= '<div class="spec-row"><dt class="micro">' . lotal_e($label) . '</dt>'
            . '<dd class="tabular">' . lotal_e(trim((string) $value)) . '</dd></div>';
    }

    return '<article class="plate card reveal card-hover" style="--d:' . ($index * 60) . 'ms">'
        . '<a class="card-media" href="' . lotal_url('/product/' . rawurlencode((string) $product['slug'])) . '">'
        . '<img src="' . lotal_e($product['image']) . '" alt="' . lotal_e($product['brand'] . ' ' . $product['name']) . '" loading="lazy">'
        . '<span class="scan"></span>'
        . '<span class="tag-abs micro">' . lotal_e($product['brand']) . '</span>'
        . ($product['featured'] ? '<span class="tag-abs gold micro">Staff pick</span>' : '')
        . '<span class="tag-abs right gold micro">Grade ' . lotal_e($product['grade']) . '</span>'
        . (!$inStock ? '<span class="tag-abs right soldout micro">Sold out</span>' : '')
        . '</a>'
        . '<div class="card-body">'
        . '<h3><a href="' . lotal_url('/product/' . rawurlencode((string) $product['slug'])) . '">' . lotal_e($product['name']) . '</a></h3>'
        . '<p class="card-sub">' . lotal_e($product['tagline']) . '</p>'
        . '<dl class="spec-list">' . $specRows . '</dl>'
        . '<div class="card-meta">'
        . '<span>' . lotal_icon('battery') . '<i class="tabular">' . (int) $product['battery_health'] . '% battery</i></span>'
        . '<span>' . lotal_icon('shield') . '<i class="tabular">' . (int) lotal_config()['warranty_days'] . '-day warranty</i></span>'
        . '</div>'
        . '<div class="card-buy">'
        . '<div><p class="micro">Price</p><p class="price-gold tabular">' . lotal_money((int) $product['price'], true) . '</p>'
        . ($saved ? '<p class="was tabular">' . lotal_money((int) $product['old_price'], true) . '</p>' : '') . '</div>'
        . '<div class="card-actions">'
        . '<form method="post" action="' . lotal_url('/cart') . '">' . lotal_csrf_field()
        . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
        . '<input type="hidden" name="qty" value="1">'
        . '<button class="btn ' . ($inStock ? 'btn-primary' : 'btn-ghost') . '" ' . ($inStock ? '' : 'disabled') . ' name="action" value="cart_add">'
        . ($inStock ? 'Add to cart' : 'Sold out') . '</button></form>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/product/' . rawurlencode((string) $product['slug'])) . '">Specs</a>'
        . '</div></div></div></article>';
}

function lotal_flash_banner(): string
{
    $flash = lotal_flash();
    if (!is_array($flash) || empty($flash['message'])) {
        return '';
    }
    $type = $flash['type'] === 'err' ? 'banner banner-err' : ($flash['type'] === 'warn' ? 'banner banner-warn' : 'banner banner-ok');
    return '<div class="wrap" style="padding-top:12px"><div class="' . $type . '">' . lotal_e($flash['message']) . '</div></div>';
}

/* ------------------------------------------------------------------ */
/* page shell                                                          */
/* ------------------------------------------------------------------ */

function lotal_layout(string $title, string $body, array $options = []): string
{
    $config = lotal_config();
    $user = lotal_current_user();
    $class = trim(($options['body_class'] ?? '') . ' ' . (empty($options['bare']) ? '' : 'bare'));

    return '<!doctype html><html lang="en"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . lotal_e($title === '' ? $config['site_name'] : $title . ' · ' . $config['site_name']) . '</title>'
        . '<meta name="description" content="Tested, graded and warrantied used laptops — MacBook, Dell, HP, Lenovo, ASUS and Surface — delivered anywhere in Ghana.">'
        . '<meta name="theme-color" content="#070B14">'
        . '<link rel="icon" href="' . lotal_asset('img/icon.svg') . '" type="image/svg+xml">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="' . lotal_asset('app.css') . '">'
        . '<script>window.LOTAL = ' . json_encode([
            'paystackPublicKey' => $config['paystack']['enabled'] ? $config['paystack']['public_key'] : null,
            'currency'          => $config['paystack']['currency'],
            'assetUrl'          => lotal_asset(''),
            'payUrl'            => lotal_url('/paystack/start'),
            'confirmUrl'        => lotal_url('/paystack/confirm'),
            'callbackUrl'       => lotal_url('/paystack/callback'),
            'deliveryFees'      => array_reduce(
                lotal_cities(),
                static fn (array $carry, string $city): array => $carry + [$city => lotal_delivery_fee($city)],
                []
            ),
            'freeDeliveryOver'  => $config['delivery_free_over'],
        ], JSON_UNESCAPED_SLASHES) . ';</script>'
        . '</head><body class="' . lotal_e($class) . '">'
        . (empty($options['chromeless']) ? lotal_nav($user) : '')
        . $body
        . (empty($options['chromeless']) ? lotal_footer() : '')
        . '<script src="' . lotal_asset('app.js') . '" defer></script>'
        . '</body></html>';
}

/** Fixed-background image band used behind hero + feature sections. */
function lotal_backdrop(string $image, string $extra = ''): string
{
    return '<div class="backdrop"><img src="' . lotal_e($image) . '" alt="" aria-hidden="true">'
        . '<span class="veil ' . lotal_e($extra) . '"></span><span class="grain"></span></div>';
}
