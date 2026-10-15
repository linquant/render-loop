<?php

declare(strict_types=1);

/**
 * Shared product catalog.
 *
 * This file is deliberately a *shared include*: both index.php and product.php
 * read from it. Editing it changes every page that renders a product — which is
 * exactly the kind of INDIRECT effect RenderLoop surfaces and `git diff` on a
 * single page would miss. See demo/README.md, scenario 3.
 */
function demo_products(): array
{
    return [
        'aeron' => ['name' => 'Aeron Chair',   'price' => '1295.00', 'stock' => 4],
        'desk'  => ['name' => 'Standing Desk',  'price' => '650.00',  'stock' => 12],
        'lamp'  => ['name' => 'Task Lamp',      'price' => '89.00',   'stock' => 0],
    ];
}

function demo_product(string $sku): ?array
{
    return demo_products()[$sku] ?? null;
}
