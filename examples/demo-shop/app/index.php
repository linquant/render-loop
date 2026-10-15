<?php

declare(strict_types=1);

require __DIR__ . '/_data.php';
require __DIR__ . '/_layout.php';

$rows = '';
foreach (demo_products() as $sku => $p) {
    $status = $p['stock'] > 0 ? 'In stock' : 'Sold out';
    $rows .= <<<HTML
        <li class="product" data-sku="{$sku}">
            <a href="/product.php?sku={$sku}">{$p['name']}</a>
            <span class="price">\${$p['price']}</span>
            <span class="stock">{$status}</span>
        </li>
HTML;
}

render_page('Catalog', <<<HTML
    <h1>Catalog</h1>
    <ul class="catalog">
        {$rows}
    </ul>
HTML);
