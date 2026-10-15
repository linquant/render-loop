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

Every action takes `-f json`. The `summary` (`lines_added`, `lines_removed`,
`hunks`, `tags_touched`) is built for a token-budgeted reader — read the summary,
only unfold the full diff when it is non-empty. The loop an agent should follow:

1. `capture -t before` the page it is about to touch,
2. make the change, and make sure the server serves the new file (watch opcache),
3. `recheck -f json` and assert the diff is empty or contains *only* the intended
   change — read `summary` first, unfold the full diff only when it is non-empty.

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
cat rendered.html | render-loop -a store -u <url> -t before   # push HTML for a JS/SPA page
```

Useful options: `-d/--dir` (snapshot directory), `--allow-host` (extra allowed
hosts), `--cookie-name` (session cookie name, default `PHPSESSID`),
`-f/--format` (`table`|`json`), `--max-hunks`, `--exit-code` (exit non-zero when
the diff is non-empty, like `git diff --exit-code`).

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
stripped, then **CSS selectors** (`config/normalizer.json`) remove noise nodes
(DebugBar, CSRF tokens, site chrome). Masking is **always by selector, never by
regex on content** — a price dropping to `0.00` must stay visible in the diff,
not be erased as noise.

`calibrate` finds the selectors for you: it captures the same page twice without
any change; whatever differs between two identical captures *is* noise
(timestamps, counters, tokens), and it prints the selectors to add to your mask.

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

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Run
`composer test` and `composer phpstan` before opening a pull request. Security
issues: please follow [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
