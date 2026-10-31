# RenderLoop demo — a test project

A tiny PHP "shop" app wired up so you can **exercise the whole RenderLoop loop**
by hand, and a script that runs every scenario and asserts the result. It lives
under `examples/` so it ships with the source but not with the Composer package.

```
examples/demo-shop/
  app/
    _data.php      shared product catalog (a shared include — see scenario 4)
    _layout.php    page chrome that injects NOISE on every request
    index.php      catalog page
    product.php    product page (the price is our "real change" target)
  golden/
    shop.json      golden-master suite (3 cases) used by scenario 5
  run-demo.sh      starts a throwaway server and walks + asserts every scenario
```

## Run everything at once

```bash
composer install                      # once, to pull the dependencies
bash examples/demo-shop/run-demo.sh   # run from the repo root
```

The script serves on port `8123` by default (also ClickHouse's default HTTP
port). If that port is taken, override it — the whole run, golden suite included,
follows the new value:

```bash
RENDER_LOOP_PORT=9000 bash examples/demo-shop/run-demo.sh
```

A green run proves capture, noise masking, calibrate, indirect-effect detection
and golden-master all work. The script is self-cleaning: it kills its server and
reverts every edit (to the demo app **and** to `config/normalizer.json`) on exit.

## What the demo is built to show

The app deliberately emits noise that changes on every request even when no code
changed — a live timestamp (`.server-time`), a per-request id (`.request-id`), a
random CSRF token, and a fake DebugBar (`.phpdebugbar`). The whole point of
RenderLoop is that this noise must **not** appear in a diff.

| Scenario | Shows |
|---|---|
| 1 · calibrate | `calibrate` captures the page twice unchanged and names the moving selectors (`.server-time`, `.request-id`) to add to `config/normalizer.json`. |
| 2 · noise masked | With the mask in place, a **no-op** re-check diffs clean despite fresh timestamp/id/token each request. |
| 3 · real edit | Dropping the Aeron price `1295.00 → 995.00` surfaces as **exactly** one `+`/`-` line, nothing else. |
| 4 · indirect effect | Editing the shared `_data.php` changes `index.php`, a page you never "touched" — RenderLoop compares *rendered state*, so `git diff` on one page would miss it. |
| 5 · golden master | `golden-record` pins the 3 cases; a no-op refactor stays **green**; a real price regression goes **red** (exit ≠ 0). |

## Do it by hand (the loop a human or an agent follows)

Run everything from the **repo root**.

```bash
# serve the app (opcache off so edits are seen immediately — see the gotcha below)
php -d opcache.enable=0 -S localhost:8123 -t examples/demo-shop/app

# in another shell:
RL="php bin/render-loop -d .render-loop/snapshots"
URL="http://localhost:8123/product.php?sku=aeron"

# 0) FIRST TIME: find the noise and mask it. calibrate prints .server-time and
#    .request-id; add both to config/normalizer.json's "noise_selectors" so they
#    don't show up as fake changes. (The default config already masks the CSRF
#    token and .phpdebugbar.)
$RL -a calibrate -u "$URL"

# 1) capture BEFORE touching anything
$RL -a capture -u "$URL" -t before

# 2) make a change, e.g. edit the Aeron price in examples/demo-shop/app/_data.php

# 3) re-check — the diff must contain ONLY your change
$RL -a recheck -u "$URL"
$RL -a recheck -u "$URL" -f json              # agent-friendly summary
$RL -a recheck -u "$URL" -f json --exit-code  # exit != 0 when the diff is non-empty
```

Golden master by hand (point `--golden-dir` at the demo's suite):

```bash
$RL -a golden-record --golden-dir=examples/demo-shop/golden --suite=shop
# … refactor …
$RL -a golden-verify --golden-dir=examples/demo-shop/golden --suite=shop   # exit != 0 on drift
```

### Gotcha: opcache

PHP's opcache keeps serving a stale file for a second or two after you edit it
(`opcache.revalidate_freq`), so a re-check can show an **empty** diff even though
you changed the code. The demo server starts with `-d opcache.enable=0` to avoid
exactly that. In a real app, make sure the server actually serves your new file
before you re-check.
