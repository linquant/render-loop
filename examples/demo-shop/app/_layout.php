<?php

declare(strict_types=1);

/**
 * Shared page chrome. On purpose it injects NOISE that changes on every request
 * even when no code changed:
 *   - .server-time  : the wall clock (temporal noise)
 *   - .request-id   : a random per-request id
 *   - csrf_token    : a random hidden token (masked by default config)
 *   - .phpdebugbar  : a fake debug toolbar (masked by default config)
 *
 * The point of RenderLoop is that this noise must NOT show up in a diff. Run
 * `calibrate` to discover the two selectors the default config doesn't know yet
 * (.server-time, .request-id); they are added to config/normalizer.json by the
 * demo script.
 */
function render_page(string $title, string $body): void
{
    $time      = date('H:i:s');                        // temporal noise
    $requestId = bin2hex(random_bytes(6));             // per-request noise
    $csrf      = bin2hex(random_bytes(16));            // per-request noise (masked by default)
    $memory    = number_format(memory_get_usage() / 1024, 1); // debugbar noise

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{$csrf}">
    <title>{$title} · RenderLoop Demo Shop</title>
</head>
<body>
    <header>
        <a href="/index.php">RenderLoop Demo Shop</a>
        <span class="server-time">Rendered at {$time}</span>
        <span class="request-id">req-{$requestId}</span>
    </header>

    <form method="post" action="/cart.php">
        <input type="hidden" name="csrf_token" value="{$csrf}">
    </form>

    <main>
        {$body}
    </main>

    <!-- fake DebugBar: masked by the default .phpdebugbar selector -->
    <div class="phpdebugbar">
        <span>Memory: {$memory} KB</span>
        <span>Rendered: {$time}</span>
    </div>
</body>
</html>
HTML;
}
