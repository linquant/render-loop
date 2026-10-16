# RenderLoop

[![CI](https://github.com/linquant/render-loop/actions/workflows/ci.yml/badge.svg)](https://github.com/linquant/render-loop/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-777bb4.svg)](composer.json)

**A render feedback loop for PHP apps.** Capture a page's rendered HTML, change
the code, then re-check — only the *real* change surfaces. The noise (DebugBar,
`<script>`, CSRF tokens, timestamps, chrome) is masked automatically.

It is a **semantic HTML diff**, not a pixel/screenshot diff, so it needs no
headless browser for server-rendered pages and stays cheap to run — in a shell,
in CI, or as the feedback signal an **AI coding agent** reads after it edits your
code.

## The loop

```
   ┌──────────────────────────────────────────────┐
   │                                                │
   ▼                                                │
 capture ──▶  you / an agent  ──▶  re-check ──▶  semantic diff
 "before"      change the code                   (noise masked)
                                                     │
                                   only the real change shows ─┘
```

1. **Capture** the rendered state *before* touching anything (`-t before`).
2. **Change** the code (by hand, or let an agent do it).
3. **Re-check** — RenderLoop re-renders, masks the noise, and shows a diff that
   should contain **only** your intended change. Anything else is a regression.

## What it catches that `git diff` doesn't

`git diff` shows *your* edit. RenderLoop compares the **rendered state** before
and after, so it also reveals **indirect or concurrent** effects: a shared
`include` that changed, a common function, a SQL query that empties a table, an
edit someone else made on the same page. That is its reason to exist on large
legacy codebases where a page has no test.

## Built for AI coding agents

RenderLoop gives an autonomous agent a tight, cheap, high-signal feedback loop:
*"did my edit do exactly what I intended, and nothing else?"*

An agent can discover the whole CLI without parsing help text:
`render-loop -a capabilities -f json` returns a machine-readable contract of every
action — its required options, the shape of its JSON output, and its exit-code
semantics. And `render-loop -a advise` (add `-f json`) returns the *modus
operandi* itself — the loop to follow, how to read the output, and the pitfalls —
so the agent learns the **how**, not just the **what**. (The same guidance lives
in [AGENTS.md](AGENTS.md) for agents that read the repo first.)

Every action takes `-f json`. The `summary` (`lines_added`, `lines_removed`,
`hunks`, `tags_touched`, `changes`) is built for a token-budgeted reader — read
the summary, only unfold the full diff when it is non-empty. The loop an agent
should follow:

1. `capture -t before` the page it is about to touch,
2. make the change, and make sure the server serves the new file (watch opcache),
3. `recheck -f json` and assert the diff is empty or contains *only* the intended
   change — read `summary` first, unfold the full diff only when it is non-empty.

The `changes` array is the **semantic** part of that summary: one entry per
localized change, so a reader never has to parse the unified diff to know what
happened.

```json
"changes": [
  {"kind": "text", "selector": "article.product-detail > p.price",
   "context": "Aeron Chair", "before": "$1295.00", "after": "$995.00"}
]
```

- `selector` — a best-effort CSS path to the element, degrading from `#id` to
  `tag.class` to a positional `tag:nth-of-type(n)`.
- `context` — the nearest heading / label text, the **anchor that stays readable
  on legacy markup** with no id or class (the `<h1>`, a `<th>`, the previous
  `<td>` of a key/value row). `null` when nothing nearby qualifies.
- `kind` — `text` (content changed), `added` or `removed` (a node appeared or
  disappeared), each with the relevant `before` / `after`.

`changes` is an enrichment, never a replacement: the unified `diff` is always the
floor. On a structural insertion that cascades the positional paths, `changes`
comes back empty and you read the diff — see the limitations below.

Note the exit code: `recheck` (and `compare`) return `0` whether or not there is
a diff, so an agent should decide from the JSON `summary` (`lines_added` /
`lines_removed`). If you prefer to branch on the exit code instead, pass
`--exit-code`: the command then returns non-zero when the diff is non-empty, like
`git diff --exit-code`. (`golden-verify` already exits non-zero on a regression.)

## Install

```bash
composer require linquant/render-loop
```

Requires PHP 8.2+ with `ext-dom`, `ext-libxml`, `ext-json`, and `ext-curl`
(curl is only needed for the fetch-based capture; the push strategy works
without it).

## CLI quickstart

```bash
# 1. capture the state BEFORE your change
vendor/bin/render-loop -a capture -u "http://localhost:8000/my_page.php" -t before

# 2. … you (or an agent) modify the page …

# 3. re-check — the diff must contain ONLY the intended change
vendor/bin/render-loop -a recheck -u "http://localhost:8000/my_page.php"
```

Other actions:

```bash
render-loop -a compare --from=<id> --to=<id>   # diff two precise snapshots
render-loop -a list [-u <url>] [-f json]       # list snapshots
render-loop -a calibrate -u <url>              # suggest noise selectors to mask
render-loop -a calibrate -u <url> --apply      # …and write them into the config
render-loop -a mask-add --selector='.chat-widget'  # add your own noise selectors
render-loop -a capabilities -f json            # machine-readable contract of every action
render-loop -a advise [-f json]                # modus operandi + pitfalls for an AI agent
cat rendered.html | render-loop -a store -u <url> -t before   # push HTML for a JS/SPA page
```

Useful options: `-d/--dir` (snapshot directory), `-c/--config` (normalizer config
file — point it at **your project's** config, not the one bundled under `vendor/`),
`--allow-host` (extra allowed hosts), `--cookie-name` (session cookie name,
default `PHPSESSID`), `-f/--format` (`table`|`json`), `--max-hunks`, `--samples`
(number of calibration captures, `>= 2`), `--apply` (calibrate writes its findings
to the config), `--selector` (`mask-add`, repeatable), `--exit-code` (exit non-zero
when the diff is non-empty, like `git diff --exit-code`).

**Authenticated pages**: pass the session cookie *value* via the
`RENDERLOOP_COOKIE` environment variable — never as a CLI argument, which would
be visible in `ps`:

```bash
RENDERLOOP_COOKIE=<value> render-loop -a capture -u "http://localhost:8000/account.php"
```

## Programmatic usage

The engine is framework-agnostic. Wire it up in a few lines:

```php
use RenderLoop\Model\SnapshotRepository;
use RenderLoop\Service\{HtmlCompareService, HtmlNormalizer, HtmlDiffer,
    NormalizerConfig, CurlCaptureStrategy, PushedHtmlStrategy, UrlGuard};

$service = new HtmlCompareService(
    new SnapshotRepository('/var/snapshots'),
    new HtmlNormalizer(NormalizerConfig::default()),
    new HtmlDiffer(),
);

// server-rendered page (curl); bounded to the allowed hosts (anti-SSRF)
$fetch = new CurlCaptureStrategy(new UrlGuard(['localhost']));
$service->capture('http://localhost/page.php', $fetch, 'before');

// … change your code …

$result = $service->recheck('http://localhost/page.php', $fetch);
if (!$result->diff->isIdentical()) {
    echo $result->diff->unifiedDiff;          // what changed
    print_r($result->diff->summary());        // compact summary (great for an AI)
}

// JS/SPA page: push the HTML already rendered by a headless browser
$service->capture('http://localhost/app', new PushedHtmlStrategy($renderedHtml), 'before');
```

### Capture strategies

`CaptureStrategy` is the extension point:

- **`CurlCaptureStrategy`** — fetches a server-rendered page. Bounded to an
  allow-list of hosts (`UrlGuard`), attaches an optional session cookie, and
  never follows redirects (a cross-origin redirect would bypass the guard).
- **`PushedHtmlStrategy`** — takes HTML already rendered elsewhere (a headless
  browser for a Vue/React page). Implement the interface for anything else.

### Noise masking & calibration

Before comparing, the HTML is normalized: `<script>`/`<style>`/comments are
stripped, then **CSS selectors** (the normalizer config) remove noise nodes
(DebugBar, CSRF tokens, site chrome). Masking is **always by selector, never by
regex on content** — a price dropping to `0.00` must stay visible in the diff,
not be erased as noise.

The config file defaults to the bundled `config/normalizer.json`, but once the
library is installed that lives under `vendor/` (read-only). Point `-c/--config`
at a file **in your own project** so the mask is yours to version and edit.

`calibrate` finds the selectors for you: it captures the same page a few times
without any change; whatever differs between identical captures *is* noise
(timestamps, counters, tokens). `--samples=N` captures more than twice to catch
slower-moving noise, and `--apply` writes the findings straight into the config
instead of printing them:

```bash
render-loop -a calibrate -u <url> --samples=4 --apply -c normalizer.json
```

**An agent can also declare its own noise**, without waiting for calibration — it
knows a chat widget or a cookie banner is noise:

```bash
render-loop -a mask-add --selector='.chat-widget' --selector='.cookie-banner' -c normalizer.json
```

Both `--apply` and `mask-add` are idempotent (no duplicate selectors) and write to
the `-c/--config` file.

### Golden master (legacy pages without tests)

Pin the current rendered output of a set of representative inputs before a
refactor, then prove it hasn't drifted (approval testing / characterization):

```bash
render-loop -a golden-record --suite=product   # pin current output (before refactor)
# … refactor …
render-loop -a golden-verify --suite=product   # exit != 0 if any output drifted
```

The manifest `golden/<suite>.json` declares the cases and the mask; the
normalized output lands in `golden/<suite>/<case>.html` — **commit it**, the diff
reads back in git like an `.approved` file. Capture against **frozen data** so
the diff reflects *code*, not *data*.

## Web UI example

A framework-agnostic web UI ships under [`examples/web`](examples/web):

```bash
php -S localhost:8000 -t examples/web
# then open http://localhost:8000/ and watch any page served by this server
```

It is the reference for integrating the engine behind your own controller: it
uses plain superglobals instead of a framework base controller, so you can see
exactly which seams to wire.

## Try the whole loop

A runnable demo app lives under
[`examples/demo-shop`](examples/demo-shop): a tiny PHP shop with deliberate noise
and a script that walks (and asserts) every scenario — noise masking, a real
edit, an indirect effect, calibrate and golden master.

```bash
bash examples/demo-shop/run-demo.sh   # from the repo root
```

## Security

The capture attaches the user's session cookie, so a free-form URL would let a
caller exfiltrate what that user sees to a third-party host. `UrlGuard` therefore
restricts capture to an http(s) scheme and an **allow-list of hosts** (the local
origin). Keep that list tight. See [SECURITY.md](SECURITY.md).

## Limitations (honest)

- Covers **rendering only** — a computation that goes to the database, an export,
  a PDF or an API stays invisible.
- Golden master covers **only the declared inputs** and assumes **deterministic**
  rendering (frozen data + mask). For a critical computation, extract the
  function and unit-test it; the loop **complements** tests, it does not replace
  them.
- The `changes` summary is **best-effort** on messy markup: with no id or class
  the `selector` is a positional path, and a structural insertion that shifts
  those paths makes `changes` fall back to empty. The unified `diff` is the
  signal you can always rely on; `changes` only ever adds to it.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Run
`composer test` and `composer phpstan` before opening a pull request. Security
issues: please follow [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
