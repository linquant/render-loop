<?php

declare(strict_types=1);

require __DIR__ . '/_data.php';
require __DIR__ . '/_layout.php';

$sku = (string) ($_GET['sku'] ?? 'aeron');
$p   = demo_product($sku);

if ($p === null) {
    http_response_code(404);
    render_page('Not found', '<h1>Unknown product</h1>');
    return;
}

$status = $p['stock'] > 0 ? "In stock ({$p['stock']} left)" : 'Sold out';

render_page($p['name'], <<<HTML
    <article class="product-detail" data-sku="{$sku}">
        <h1>{$p['name']}</h1>
        <p class="price">\${$p['price']}</p>
        <p class="stock">{$status}</p>
        <button type="submit">Add to cart</button>
    </article>
HTML);
