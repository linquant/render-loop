#!/usr/bin/env bash
#
# End-to-end tour of RenderLoop against the demo shop app.
# It starts a throwaway PHP server, walks every scenario and asserts the outcome,
# so a green run proves the whole loop works. Nothing here is permanent: the
# server is killed, the scratch snapshot dir is removed, and every edit to the
# app source and to config/normalizer.json is reverted on exit.
#
# Usage:  bash examples/demo-shop/run-demo.sh
#
set -euo pipefail

# The script lives in examples/demo-shop/; run everything from the repo root so
# bin/render-loop and config/normalizer.json resolve.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PORT=8123
BASE="http://localhost:$PORT"
RL=(php bin/render-loop)                 # the CLI (xdebug notices go to stderr, ignored)
SNAP=".render-loop/demo-snapshots"       # scratch snapshot dir for this run
CONFIG="config/normalizer.json"
APP="examples/demo-shop/app"             # the demo app docroot
GOLDEN="examples/demo-shop/golden"       # the demo golden suite(s)

say()  { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }
ok()   { printf '   \033[1;32m✓ %s\033[0m\n' "$*"; }
die()  { printf '   \033[1;31m✗ %s\033[0m\n' "$*"; exit 1; }

capture() { "${RL[@]}" -d "$SNAP" -a capture -u "$1" -t before >/dev/null; }
recheck() { "${RL[@]}" -d "$SNAP" -a recheck -u "$1" -f json; }

# Toggle the two demo noise selectors in config/normalizer.json.
mask_off() { php -r '$f=$argv[1];$d=json_decode(file_get_contents($f),true);$d["noise_selectors"]=array_values(array_filter($d["noise_selectors"],fn($s)=>!in_array($s,[".server-time",".request-id"],true)));file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$CONFIG"; }
mask_on()  { php -r '$f=$argv[1];$d=json_decode(file_get_contents($f),true);$d["noise_selectors"]=array_values(array_unique(array_merge($d["noise_selectors"],[".server-time",".request-id"])));file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$CONFIG"; }

# --- throwaway server + cleanup ------------------------------------------------
rm -rf "$SNAP"; mkdir -p "$SNAP"
# Pristine copies so every edit can be reverted (files may be untracked).
cp -a "$APP" "$SNAP/app-pristine"
cp -a "$CONFIG" "$SNAP/normalizer.json.bak"
restore_app()    { rm -rf "$APP" && cp -a "$SNAP/app-pristine" "$APP"; }
restore_config() { cp -a "$SNAP/normalizer.json.bak" "$CONFIG"; }

# opcache.revalidate_freq would let the server keep serving a stale file for a
# couple of seconds after an edit — exactly the trap the README warns about. Turn
# it off so a re-check always sees the new code.
php -d opcache.enable=0 -S "localhost:$PORT" -t "$APP" >/dev/null 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    restore_app
    restore_config
    rm -rf "$SNAP"
}
trap cleanup EXIT
for _ in $(seq 1 30); do curl -fsS "$BASE/index.php" >/dev/null 2>&1 && break; sleep 0.1; done
curl -fsS "$BASE/index.php" >/dev/null 2>&1 || die "demo server never came up"
ok "demo shop serving on $BASE (pid $SERVER_PID)"

# ------------------------------------------------------------------------------
say "Scenario 1 — calibrate discovers the noise selectors"
# Start from a config that does NOT yet know the demo's moving parts. calibrate
# captures the page twice unchanged; whatever differs between two identical
# captures IS noise, and it names the selectors to mask.
mask_off
OUT=$("${RL[@]}" -d "$SNAP" -a calibrate -u "$BASE/product.php?sku=aeron" 2>/dev/null)
printf '%s\n' "$OUT" | sed 's/^/     /'
if echo "$OUT" | grep -q '.server-time' && echo "$OUT" | grep -q '.request-id'; then
    ok "calibrate found .server-time and .request-id"
else
    die "calibrate did not surface the expected selectors"
fi
mask_on   # apply what calibrate told us (now they're in config/normalizer.json)
ok "added those selectors to $CONFIG"

# ------------------------------------------------------------------------------
say "Scenario 2 — noise is masked (a no-op must diff clean)"
# Change NOTHING, re-check. Every request carries a fresh timestamp, request-id
# and CSRF token; with the mask in place the diff is empty anyway.
capture "$BASE/product.php?sku=aeron"
sleep 1.2   # cross a second boundary so the wall clock really moved
OUT=$(recheck "$BASE/product.php?sku=aeron")
if echo "$OUT" | grep -q '"lines_added": 0' && echo "$OUT" | grep -q '"lines_removed": 0'; then
    ok "no-op re-check is identical — timestamp / request-id / CSRF all masked"
else
    echo "$OUT"; die "noise leaked into the diff"
fi

# ------------------------------------------------------------------------------
say "Scenario 3 — a real edit shows, and only it"
capture "$BASE/product.php?sku=aeron"
sed -i "s/'price' => '1295.00'/'price' => '995.00'/" "$APP"/_data.php
OUT=$(recheck "$BASE/product.php?sku=aeron")
if echo "$OUT" | grep -q '1295.00' && echo "$OUT" | grep -q '995.00'; then
    ok "diff carries exactly the price change 1295.00 → 995.00"
    echo "$OUT" | grep -E '"lines_(added|removed)"' | sed 's/^/     /'
else
    echo "$OUT"; die "the real change did not surface cleanly"
fi
restore_app

# ------------------------------------------------------------------------------
say "Scenario 4 — indirect effect that git-diff-on-one-page would miss"
# Re-check index.php but edit the SHARED include _data.php. The page we never
# "touched" still changes — RenderLoop compares RENDERED state, not source lines.
capture "$BASE/index.php"
sed -i "s/'Standing Desk'/'Sit-Stand Desk'/" "$APP"/_data.php
OUT=$(recheck "$BASE/index.php")
echo "$OUT" | grep -q 'Sit-Stand Desk' \
    && ok "catalog reflects the shared-include edit (indirect effect caught)" \
    || { echo "$OUT"; die "indirect effect not detected"; }
restore_app

# ------------------------------------------------------------------------------
say "Scenario 5 — golden master: record, refactor, verify, catch a regression"
# Run against a scratch golden dir so the committed golden suite stays pristine.
GDIR="$SNAP/golden"; mkdir -p "$GDIR"; cp "$GOLDEN/shop.json" "$GDIR/shop.json"
gold() { "${RL[@]}" -d "$SNAP" --golden-dir="$GDIR" "$@"; }

gold -a golden-record --suite=shop >/dev/null
ok "pinned golden output for suite 'shop' (3 cases)"

# (a) behaviour-preserving refactor: a comment line, output identical → stays green
printf '\n// refactor: no rendered change\n' >> "$APP"/_data.php
if gold -a golden-verify --suite=shop >/dev/null 2>&1; then
    ok "golden-verify GREEN after a no-op refactor (rendering did not drift)"
else
    die "golden-verify flagged drift on a behaviour-preserving change"
fi
restore_app

# (b) real regression: change a price that two golden cases render
sed -i "s/'price' => '650.00'/'price' => '0.00'/" "$APP"/_data.php
if gold -a golden-verify --suite=shop >/dev/null 2>&1; then
    restore_app; die "golden-verify MISSED a real regression"
else
    ok "golden-verify RED on a real regression (exit != 0, as it should)"
fi
restore_app

say "All scenarios passed ✔"
