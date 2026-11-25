<?php
/**
 * LOTAL GHANA — storefront pages.
 * Each function returns a complete HTML document (layout included).
 */

declare(strict_types=1);

function lotal_hero_words(): array
{
    return ['new hardware.', 'pro equipment.', 'a lab machine.', 'our own machine.'];
}

function lotal_page_home(array $products, array $brands, array $testimonials): string
{
    $config = lotal_config();
    $warranty = (int) $config['warranty_days'];
    $points = (int) $config['diagnostic_points'];
    $hero = $products[0] ?? null;
    $featured = array_slice($products, 0, 6);

    /* ---- hero ---- */
    $words = '';
    foreach (lotal_hero_words() as $index => $word) {
        $words .= '<span class="word" data-word="' . $index . '">' . lotal_e($word) . '</span>';
    }

    $hero = $hero ? '<aside class="glass float hero-card">'
        . '<div class="hero-card-top"><span class="micro">Featured unit / in stock</span>'
        . '<span class="tabular gold">GRADE ' . lotal_e($hero['grade']) . '</span></div>'
        . '<img src="' . lotal_e($hero['image']) . '" alt="' . lotal_e($hero['name']) . '">'
        . '<div class="hero-card-body">'
        . '<h2>' . lotal_e($hero['name']) . '</h2>'
        . '<dl>'
        . '<div><dt class="micro">Processor</dt><dd class="tabular">' . lotal_e($hero['processor']) . '</dd></div>'
        . '<div><dt class="micro">Memory</dt><dd class="tabular">' . lotal_e($hero['ram']) . '</dd></div>'
        . '<div><dt class="micro">Storage</dt><dd class="tabular">' . lotal_e($hero['storage']) . '</dd></div>'
        . '<div><dt class="micro">Battery health</dt><dd class="tabular">' . (int) $hero['battery_health'] . '%</dd></div>'
        . '</dl>'
        . '<div class="hero-card-buy"><div><p class="micro">Price</p>'
        . '<p class="tabular big-gold">' . lotal_money((int) $hero['price'], true) . '</p></div>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/product/' . rawurlencode((string) $hero['slug'])) . '">View unit ' . lotal_icon('arrow') . '</a>'
        . '</div></div></aside>' : '';

    $chips = '';
    foreach ([['Free', 'diagnostic report'], [$warranty . ' days', 'warranty'], ['7 days', 'return window'], ['16 cities', 'delivery']] as [$strong, $rest]) {
        $chips .= '<li><span class="tabular">' . lotal_e($strong) . '</span><span class="muted">' . lotal_e($rest) . '</span></li>';
    }

    $body = '<section class="hero">'
        . lotal_backdrop(lotal_asset('img/hero.jpg'), 'hero-veil')
        . '<div class="wrap hero-grid">'
        . '<div>'
        . '<div class="reveal"><p class="pulse-row"><i class="dot"></i><span class="micro micro-gold">01 / '
        . lotal_e(strtoupper($config['site_name'])) . ' — OSU WORKSHOP, ACCRA · OPEN MON–SAT</span></p></div>'
        . '<h1 class="display hero-title reveal">'
        . '<span>Every used laptop,</span><span>tested like</span>'
        . '<span class="word-swap"><span class="word-rot" data-rotate>' . $words . '</span></span></h1>'
        . '<p class="lede reveal">' . lotal_e('Fairly used MacBooks, Dell, HP, Lenovo, ASUS and Surface laptops — sourced from corporate fleet upgrades, opened on our bench, graded honestly and delivered anywhere in Ghana with a warranty you can actually claim.') . '</p>'
        . '<div class="btn-row reveal"><a class="btn btn-primary" href="' . lotal_url('/shop') . '">Browse inventory ' . lotal_icon('arrow') . '</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/track') . '">Track an order</a></div>'
        . '<ul class="hero-chips reveal">' . $chips . '</ul>'
        . '</div>' . $hero
        . '</div>'
        . '<div class="hero-band">' . lotal_marquee([
            'MACBOOK PRO & AIR IN STOCK', 'THINKPAD X1 CARBON — GRADE A', 'DELL XPS 13 9310',
            'HP ELITEBOOK 840 G7', 'SURFACE LAPTOP 4', 'ASUS ZENBOOK 14 OLED', 'PAY ON DELIVERY IN ACCRA',
        ]) . '</div></section>';

    /* ---- stats ---- */
    $stats = '';
    foreach ([['2,480', 'Laptops delivered', 'since 2021'], [(string) $warranty, 'Day warranty', 'parts & labour'], ['16', 'Cities served', 'nationwide'], ['4.9', 'Average rating', '1,102 reviews']] as [$value, $label, $note]) {
        $stats .= '<div class="stat plate reveal"><p class="tabular stat-value">' . lotal_e($value) . '</p>'
            . '<p>' . lotal_e($label) . '</p><p class="micro">' . lotal_e($note) . '</p></div>';
    }
    $body .= '<section class="wrap stats">' . $stats . '</section>';

    /* ---- inventory with sticky rail ---- */
    $railRows = '<li><a href="' . lotal_url('/shop') . '"><span class="micro">All brands</span>'
        . '<span class="tabular muted">' . count($products) . '</span></a></li>';
    foreach ($brands as $brand) {
        $railRows .= '<li><a href="' . lotal_url('/shop?brand=' . rawurlencode((string) $brand['brand'])) . '">'
            . '<span class="micro">' . lotal_e($brand['brand']) . '</span>'
            . '<span class="tabular muted">' . (int) $brand['count'] . '</span></a></li>';
    }

    $cards = '';
    foreach ($featured as $index => $product) {
        $cards .= lotal_product_card($product, $index);
    }

    $body .= '<section class="wrap inv" id="inventory"><div class="inv-grid">'
        . '<aside class="inv-rail"><div class="reveal">'
        . '<p class="micro micro-gold">02 / Inventory</p>'
        . '<h2 class="display sec-title">Graded stock,<br>ready today.</h2>'
        . '<p class="muted">Each laptop is one of one. When it is gone it is gone — we do not restock the same machine twice.</p>'
        . '<ul class="rail-list">' . $railRows . '</ul>'
        . '<a class="btn btn-ghost wide" href="' . lotal_url('/shop') . '">Open full inventory ' . lotal_icon('arrow') . '</a>'
        . '</div></aside>'
        . '<div class="grid-3">' . $cards . '</div>'
        . '</div></section>';

    /* ---- process ---- */
    $steps = [
        ['01', 'Sourced & wiped', 'Units come from corporate fleet upgrades and trade-ins. Every drive is securely erased and the BIOS cleared before it reaches the bench.', 'box'],
        ['02', $points . '-point diagnostic', 'We test the panel, ports, keyboard, hinges, thermals, battery health and cycle count. The report is printed and packed with the machine.', 'battery'],
        ['03', 'Graded, priced, warranted', 'Cosmetic condition is graded A to B+, photographed honestly and priced against the Ghanaian market. Then ' . $warranty . ' days of warranty starts.', 'shield'],
    ];
    $stepCards = '';
    foreach ($steps as [$number, $title, $text, $icon]) {
        $stepCards .= '<div class="glass step reveal"><div class="step-top"><span class="tabular">' . $number . '</span>'
            . '<span class="rule"></span>' . lotal_icon($icon) . '</div>'
            . '<h3 class="display">' . lotal_e($title) . '</h3><p class="muted">' . lotal_e($text) . '</p></div>';
    }
    $body .= '<section class="band" id="how">' . lotal_backdrop(lotal_asset('img/inspection.jpg'), 'band-veil')
        . '<div class="wrap band-in"><p class="micro micro-gold">03 / How grading works</p>'
        . '<h2 class="display big-title">Nothing leaves the bench untested.</h2>'
        . '<div class="grid-3 steps">' . $stepCards . '</div>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . lotal_url('/shop') . '">See what is on the bench ' . lotal_icon('arrow') . '</a>'
        . '<span class="micro">Grades: A / A- / B+ · every mark photographed</span></div>'
        . '</div></section>';

    /* ---- warranty ---- */
    $trust = [
        ['shield', $warranty . '-day warranty', 'Parts and labour covered on every unit. Faulty within ' . $warranty . ' days and we repair, replace or refund — your choice.'],
        ['box', '7-day return window', 'Change your mind within a week, send the machine back in its original condition and we refund in full.'],
        ['check', 'Written inspection report', 'Battery health, cycle count and every cosmetic mark documented before you pay. Nothing hidden.'],
        ['lock', 'Paystack protected payments', 'Card, bank transfer, MTN MoMo, Telecel Cash and AT Money — settled by Paystack, never stored by us.'],
    ];
    $trustCards = '';
    foreach ($trust as [$icon, $title, $text]) {
        $trustCards .= '<div class="plate card-hover reveal"><span class="ico">' . lotal_icon($icon) . '</span>'
            . '<h3 class="display">' . lotal_e($title) . '</h3><p class="muted">' . lotal_e($text) . '</p></div>';
    }
    $metrics = '';
    foreach ([[(string) $points, 'diagnostic checks'], [(string) $warranty, 'days of cover'], ['7', 'day returns'], ['48hr', 'repair turnaround']] as [$value, $label]) {
        $metrics .= '<div class="plate-flat metric"><p class="tabular">' . lotal_e($value) . '</p><p class="micro">' . lotal_e($label) . '</p></div>';
    }
    $body .= '<section class="wrap warranty" id="warranty"><div class="two-col">'
        . '<div><p class="micro micro-gold">04 / Why LOTAL</p>'
        . '<h2 class="display big-title">The warranty is<br>the whole point.</h2>'
        . '<p class="lede">Anyone can photograph a laptop and post it online. We back ours with a workshop, a technician and a '
        . $warranty . '-day promise written on your invoice — plus a tracking code so you always know where your machine is.</p>'
        . '<div class="metric-grid">' . $metrics . '</div></div>'
        . '<div class="grid-2">' . $trustCards . '</div>'
        . '</div></section>';

    /* ---- delivery ---- */
    $zones = [
        ['Accra & Tema', 'Same day / next day', lotal_money(lotal_delivery_fee('Accra'), true) . ' – ' . lotal_money(lotal_delivery_fee('Tema'), true)],
        ['Kumasi', '1 – 2 working days', lotal_money(lotal_delivery_fee('Kumasi'), true)],
        ['Takoradi & Cape Coast', '2 working days', lotal_money(lotal_delivery_fee('Takoradi'), true)],
        ['Tamale & Northern', '2 – 4 working days', lotal_money(lotal_delivery_fee('Tamale'), true)],
    ];
    $zoneRows = '';
    foreach ($zones as [$city, $time, $fee]) {
        $zoneRows .= '<li><div><p>' . lotal_e($city) . '</p><p class="micro">' . lotal_icon('clock') . ' ' . lotal_e($time) . '</p></div>'
            . '<p class="tabular gold">' . lotal_e($fee) . '</p></li>';
    }
    $body .= '<section class="band"><div class="wrap two-col band-in">'
        . '<div><p class="micro micro-gold">05 / Delivery</p>'
        . '<h2 class="display big-title">From our bench in Osu to your door.</h2>'
        . '<p class="lede">Every laptop travels in a foam-lined box with its charger, the inspection report and a receipt. '
        . 'Riders call before arrival and you can inspect the machine before you sign.</p>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . lotal_url('/track') . '">' . lotal_icon('truck') . ' Track a delivery</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/shop') . '">Shop now</a></div></div>'
        . '<div class="glass zones"><div class="zones-top"><span class="micro">' . lotal_icon('pin') . ' Delivery zones &amp; fees</span>'
        . '<span class="tabular micro">EST. TIME</span></div><ul>' . $zoneRows . '</ul>'
        . '<p class="micro">Delivery fees are added at checkout. Free delivery on orders above '
        . lotal_money((int) $config['delivery_free_over'], true) . ' within Greater Accra.</p></div>'
        . '</div>' . lotal_backdrop(lotal_asset('img/delivery.jpg'), 'band-veil flip') . '</section>';

    /* ---- testimonials ---- */
    $slides = '';
    foreach ($testimonials as $index => $item) {
        $stars = str_repeat('★', max(1, min(5, (int) $item['rating'])));
        $slides .= '<div class="quote' . ($index === 0 ? ' is-active' : '') . '" data-slide="' . $index . '">'
            . '<p class="stars">' . $stars . '</p>'
            . '<blockquote>“' . lotal_e($item['quote']) . '”</blockquote>'
            . '<div class="quote-by"><span class="avatar">' . lotal_e(implode('', array_map(
                static fn (string $part): string => strtoupper(mb_substr($part, 0, 1)),
                explode(' ', (string) $item['name'])
            ))) . '</span><div><p>' . lotal_e($item['name']) . '</p>'
            . '<p class="micro">' . lotal_e($item['role'] . ' · ' . $item['city']) . '</p></div></div></div>';
    }
    if ($testimonials !== []) {
        $body .= '<section class="wrap"><div class="flex-between"><div>'
            . '<p class="micro micro-gold">06 / Customers</p>'
            . '<h2 class="display big-title">Bought, used, reviewed.</h2></div>'
            . '<p class="muted narrow">Reviews are collected from verified purchases only — customers who actually received a machine from us.</p>'
            . '</div><div class="plate grain quotes reveal" data-carousel>' . $slides
            . '<div class="quote-nav"><button data-prev aria-label="Previous">‹</button>'
            . '<span class="tabular" data-count>01 / ' . str_pad((string) count($testimonials), 2, '0', STR_PAD_LEFT) . '</span>'
            . '<button data-next aria-label="Next">›</button></div></div></section>';
    }

    /* ---- image carousel + CTA ---- */
    $slidesData = [
        [lotal_asset('img/inspection.jpg'), '01 / The bench', 'Every machine is opened, tested and resealed.', 'Battery health, cycle count, ports, hinges and thermals — checked before a laptop is listed.'],
        [lotal_asset('img/hero.jpg'), '02 / The grade', 'Photographed honestly, graded A to B+.', 'If a scuff matters to you, you will have read about it before your card is charged.'],
        [lotal_asset('img/laptop-black.jpg'), '03 / The stock', 'Corporate fleet upgrades and trade-ins.', 'MacBook, ThinkPad, Latitude, EliteBook, ZenBook and Surface — retired after two careful years.'],
        [lotal_asset('img/delivery.jpg'), '04 / The delivery', 'Packed in foam, tracked to your door.', 'Charger, sleeve, inspection report and a unique tracking code travel with every order.'],
    ];
    $carousel = '';
    $dots = '';
    foreach ($slidesData as $index => [$image, $label, $title, $text]) {
        $carousel .= '<figure class="slide' . ($index === 0 ? ' is-active' : '') . '" data-slide="' . $index . '">'
            . '<img src="' . lotal_e($image) . '" alt="">'
            . '<figcaption><span class="micro micro-gold">' . lotal_e($label) . '</span>'
            . '<h3 class="display">' . lotal_e($title) . '</h3><p>' . lotal_e($text) . '</p></figcaption></figure>';
        $dots .= '<button data-dot="' . $index . '" class="' . ($index === 0 ? 'on' : '') . '" aria-label="Slide ' . ($index + 1) . '"></button>';
    }
    $body .= '<section class="image-carousel" data-image-carousel>' . $carousel
        . '<div class="carousel-ui"><div class="dots">' . $dots . '</div>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/shop') . '">Shop the stock ' . lotal_icon('arrow') . '</a></div></section>';

    $body .= '<section class="wrap" id="about"><div class="plate grain cta reveal">'
        . '<p class="micro micro-gold">07 / Open an account</p>'
        . '<h2 class="display cta-title">Create an account, then buy with confidence.</h2>'
        . '<p class="lede centre">Registration takes under a minute and unlocks checkout, order history, saved delivery details and '
        . 'priority warranty support. Already bought from us? Track your order with the code on your receipt — no account needed.</p>'
        . '<div class="btn-row centre"><a class="btn btn-primary" href="' . lotal_url('/register') . '">Create account ' . lotal_icon('arrow') . '</a>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/track') . '">Track my order</a></div>'
        . '</div></section>';

    return lotal_layout('Graded Used &amp; Fairly Used Laptops in Ghana', $body, ['body_class' => 'home']);
}

/* ------------------------------------------------------------------ */

function lotal_page_shop(array $products, array $brands, string $brand, string $query, string $sort): string
{
    $chips = '';
    foreach (array_merge(['All'], array_column($brands, 'brand')) as $entry) {
        $active = $entry === ($brand === '' ? 'All' : $brand);
        $chips .= '<a class="chip-f ' . ($active ? 'on' : '') . '" href="' . lotal_url('/shop?brand=' . rawurlencode($entry)
            . ($query !== '' ? '&q=' . rawurlencode($query) : '')) . '">' . lotal_e($entry) . '</a>';
    }

    $sorts = ['featured' => 'Featured', 'price-asc' => 'Price: low to high', 'price-desc' => 'Price: high to low', 'newest' => 'Newest in'];
    $options = '';
    foreach ($sorts as $value => $label) {
        $options .= '<option value="' . lotal_e($value) . '"' . ($sort === $value ? ' selected' : '') . '>' . lotal_e($label) . '</option>';
    }

    $grid = '';
    if ($products === []) {
        $grid = '<div class="plate-flat empty"><p class="display">No units match that search.</p>'
            . '<p class="muted">Try another brand or clear the filters — stock changes every week.</p>'
            . '<a class="btn btn-primary" href="' . lotal_url('/shop') . '">Reset filters</a></div>';
    } else {
        foreach ($products as $index => $product) {
            $grid .= lotal_product_card($product, $index);
        }
    }

    $body = '<section class="page-head">' . lotal_backdrop(lotal_asset('img/inspection.jpg'), 'page-veil')
        . '<div class="wrap"><p class="micro micro-gold">Inventory / all graded stock</p>'
        . '<h1 class="display page-title">Every machine on the bench.</h1>'
        . '<p class="lede">Each laptop is inspected, graded and photographed as an individual unit. Specs, battery health and '
        . 'cosmetic notes are listed in full — no surprises when the box arrives.</p></div></section>'
        . '<section class="wrap"><form class="glass filters" method="get" action="' . lotal_url('/shop') . '">'
        . '<label class="search-box">' . lotal_icon('search')
        . '<input type="search" name="q" value="' . lotal_e($query) . '" placeholder="Search by model, brand or processor…" aria-label="Search"></label>'
        . '<div class="chips">' . $chips . '</div>'
        . '<div class="sort-box"><label class="micro" for="sort">Sort</label><select id="sort" name="sort">' . $options . '</select>'
        . '<input type="hidden" name="brand" value="' . lotal_e($brand) . '">'
        . '<button class="btn btn-primary sm" type="submit">Apply</button></div></form>'
        . '<div class="result-bar"><p class="micro">Showing <b class="tabular">' . str_pad((string) count($products), 2, '0', STR_PAD_LEFT) . '</b> '
        . (count($products) === 1 ? 'unit' : 'units') . ($brand !== '' ? ' · ' . lotal_e($brand) : '')
        . ($query !== '' ? ' · “' . lotal_e($query) . '”' : '') . '</p>'
        . '<p class="micro">Updated today · Osu workshop</p></div>'
        . '<div class="grid-4">' . $grid . '</div></section>';

    return lotal_layout('Inventory — Graded Used Laptops', $body);
}

/* ------------------------------------------------------------------ */

function lotal_page_product(array $product, array $related): string
{
    $config = lotal_config();
    $warranty = (int) $config['warranty_days'];
    $inStock = (int) $product['stock'] > 0;
    $saved = $product['old_price'] && $product['old_price'] > $product['price'];

    $specs = [
        ['Processor', $product['processor']], ['Graphics', $product['graphics']], ['Memory', $product['ram']],
        ['Storage', $product['storage']], ['Display', $product['screen']], ['Operating system', $product['os']],
        ['Ports', $product['ports']], ['Weight', $product['weight']],
        ['Battery health', $product['battery_health'] . '% of original capacity'],
        ['Cosmetic grade', 'Grade ' . $product['grade']],
        ['Warranty', $warranty . ' days — parts & labour'],
    ];
    $specRows = '';
    foreach ($specs as [$label, $value]) {
        $specRows .= '<div class="spec-line"><span class="micro">' . lotal_e($label) . '</span>'
            . '<span class="tabular">' . lotal_e($value) . '</span></div>';
    }

    $thumbs = '';
    foreach (['inspection.jpg' => 'Bench inspection', 'hero.jpg' => 'Chassis detail', 'delivery.jpg' => 'Packed & delivered'] as $file => $alt) {
        $thumbs .= '<div class="plate-flat"><img src="' . lotal_asset('img/' . $file) . '" alt="' . lotal_e($alt) . '"></div>';
    }

    $highlights = '';
    foreach ((array) $product['highlights'] as $highlight) {
        $highlights .= '<li>' . lotal_icon('check') . '<span>' . lotal_e($highlight) . '</span></li>';
    }

    $trust = [
        ['shield', $warranty . '-day warranty — parts & labour'],
        ['box', '7-day return window, no questions'],
        ['truck', 'Delivery in 1–3 working days nationwide'],
        ['lock', 'Card, bank & mobile money via Paystack'],
    ];
    $trustRows = '';
    foreach ($trust as [$icon, $text]) {
        $trustRows .= '<div class="plate-flat trust-row">' . lotal_icon($icon) . '<p class="muted">' . lotal_e($text) . '</p></div>';
    }

    $fees = '';
    foreach (['Accra', 'Kumasi', 'Takoradi', 'Tamale'] as $city) {
        $fees .= '<li><span>' . lotal_e($city === 'Takoradi' ? 'Takoradi / Cape Coast' : $city) . '</span>'
            . '<span class="tabular">' . lotal_money(lotal_delivery_fee($city), true) . '</span></li>';
    }

    $buyForm = '<form method="post" action="' . lotal_url('/cart') . '" class="buy-form">' . lotal_csrf_field()
        . '<input type="hidden" name="product_id" value="' . lotal_e($product['id']) . '">'
        . '<div class="qty"><button type="submit" name="action" value="cart_dec" aria-label="Decrease">−</button>'
        . '<input class="tabular" type="number" name="qty" value="1" min="1" max="' . max(1, (int) $product['stock']) . '" aria-label="Quantity">'
        . '<button type="submit" name="action" value="cart_inc" aria-label="Increase">+</button></div>'
        . '<button class="btn btn-primary wide" name="action" value="cart_add" type="submit"' . ($inStock ? '' : ' disabled') . '>'
        . ($inStock ? 'Add to cart' : 'Sold out') . '</button></form>'
        . '<a class="btn btn-ghost wide" href="' . lotal_url(lotal_current_user() ? '/checkout' : '/login?next=%2Fcheckout') . '">'
        . (lotal_current_user() ? 'Buy now — checkout' : 'Sign in to buy') . '</a>';

    $relatedGrid = '';
    foreach ($related as $index => $item) {
        $relatedGrid .= lotal_product_card($item, $index);
    }
    $relatedBlock = $related === [] ? '' : '<section class="wrap related"><div class="flex-between">'
        . '<div><p class="micro micro-gold">More from ' . lotal_e($product['brand']) . '</p>'
        . '<h2 class="display big-title">Also on the bench.</h2></div>'
        . '<a class="btn btn-ghost" href="' . lotal_url('/shop?brand=' . rawurlencode((string) $product['brand'])) . '">See all ' . lotal_e($product['brand']) . '</a></div>'
        . '<div class="grid-3">' . $relatedGrid . '</div></section>';

    $body = '<section class="wrap product">'
        . '<a class="back" href="' . lotal_url('/shop') . '">‹ Back to inventory</a>'
        . '<div class="product-grid"><div>'
        . '<div class="plate gallery"><img src="' . lotal_e($product['image']) . '" alt="' . lotal_e($product['brand'] . ' ' . $product['name']) . '">'
        . '<span class="scan"></span><span class="tag-abs micro">' . lotal_e($product['brand']) . '</span>'
        . '<span class="tag-abs right gold micro">Grade ' . lotal_e($product['grade']) . '</span></div>'
        . '<div class="thumbs">' . $thumbs . '</div>'
        . '<div class="plate-flat spec-box"><div class="spec-head"><p class="micro micro-gold">Full specification</p>'
        . '<span class="rule"></span><p class="micro">' . (int) $config['diagnostic_points'] . '-point check passed</p></div>'
        . '<dl>' . $specRows . '</dl></div>'
        . '</div><aside class="product-buy">'
        . '<p class="micro micro-gold">' . lotal_e($product['brand'] . ' / ' . $product['name']) . '</p>'
        . '<h1 class="display page-title-sm">' . lotal_e($product['name']) . '</h1>'
        . '<p class="muted">' . lotal_e($product['tagline']) . '</p>'
        . '<div class="price-row"><p class="tabular huge-gold">' . lotal_money((int) $product['price']) . '</p>'
        . ($saved ? '<p class="tabular was">was ' . lotal_money((int) $product['old_price']) . '</p>' : '') . '</div>'
        . '<p class="micro">Price includes charger, sleeve &amp; inspection report · delivery added at checkout</p>'
        . '<div class="plate-flat battery-box"><span class="micro">' . lotal_icon('battery') . ' Battery health</span>'
        . '<b class="tabular">' . (int) $product['battery_health'] . '%</b>'
        . '<div class="bar"><i style="width:' . max(6, min(100, (int) $product['battery_health'])) . '%"></i></div>'
        . '<p class="micro">Measured on our bench. Anything below 85% is replaced before sale.</p></div>'
        . '<div class="note-box"><p class="micro micro-gold">Honest condition note</p><p>' . lotal_e($product['grade_note']) . '</p></div>'
        . '<ul class="ticks">' . $highlights . '</ul>'
        . $buyForm
        . '<p class="micro stock">' . ($inStock
            ? (int) $product['stock'] . ' unit(s) available · stock is one-of-one'
            : 'Sold out — browse the inventory for the next unit') . '</p>'
        . '<div class="trust-list">' . $trustRows . '</div>'
        . '<div class="plate-flat fees"><p class="micro">' . lotal_icon('pin') . ' Delivery estimate</p><ul>' . $fees . '</ul></div>'
        . '</aside></div></section>'
        . $relatedBlock;

    return lotal_layout($product['brand'] . ' ' . $product['name'] . ' — Grade ' . $product['grade'], $body);
}
