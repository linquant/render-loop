# AGENTS.md

Modus operandi for an AI agent working **with** RenderLoop (using it as a feedback
loop) or **on** RenderLoop (editing this codebase).

> **Canonical, runtime source of truth:** `render-loop -a advise` (add `-f json`
> for a machine-readable version). This file mirrors it for agents that read the
> repo before running anything; if the two ever disagree, the CLI wins. The
> full action contract is `render-loop -a capabilities -f json`.

## Using RenderLoop as your feedback loop

RenderLoop answers one question after you edit code: *"did my change do exactly
what I intended, and nothing else?"* It is a **semantic HTML diff** of a page's
rendered state before vs. after — so it also catches **indirect** effects (a
shared include, a common function, a query that empties a table) that `git diff`
never shows.

### The loop to follow

1. **Discover the CLI before guessing** — `render-loop -a capabilities -f json`
   returns every action, its required options, output shape and exit-code
   semantics.
2. **Capture BEFORE you touch anything** — `render-loop -a capture -u <url> -t before`.
   No "before" snapshot means nothing to diff against.
3. **Make your change**, then make sure the server actually serves the new file
   (watch opcache / build steps) before re-checking.
4. **Re-check** — `render-loop -a recheck -u <url> -f json`. The diff should
   contain **only** your intended change. Anything else is a regression.
5. **Assert the blast radius** — rather than eyeballing the diff, declare the only
   selector your edit may touch: `render-loop -a recheck -u <url>
   --expect-selector='.price' -f json` (repeatable). You get a binary verdict
   (`assertion.satisfied`) and, on a leak, the isolated out-of-scope diff plus a
   non-zero exit. Declaring the intent **before** you see the diff is what stops
   you from rationalizing an unexpected change away. This is a **per-edit** guard,
   not a frozen regression net — that is golden master (below).
6. **JS/SPA pages** — render the HTML elsewhere and push it:
   `cat rendered.html | render-loop -a store -u <url> -t before`.
7. **Legacy pages fed by an internal AJAX call** — don't snapshot the shell (the
   data isn't in it, so the diff stays blind to it): snapshot the fragment
   endpoint directly with `render-loop -a capture -u <endpoint> --ajax` (adds the
   `X-Requested-With` header), or `--method POST --data '…'` for a POST endpoint.
   No browser needed when the AJAX call is a plain HTTP request.

### Reading the output

- Read **`summary` first** (`lines_added`, `lines_removed`, `hunks`,
  `tags_touched`, `changes`). Unfold the full `diff` only when the summary is
  non-empty — it keeps your token budget low.
- The **`changes`** array is the semantic layer: one entry per localized change
  (`kind` / `selector` / `context` / `before` / `after`), so you need not parse
  the unified diff to know what happened.
- **Decide from the JSON summary, not the exit code**: `recheck` / `compare`
  return `0` even with a diff. Pass `--exit-code` to get a non-zero status on a
  non-empty diff, like `git diff --exit-code`.

### Pitfalls

- **Masking is by selector, never by regex on content**: a price dropping to
  `0.00` must stay visible in the diff, not be erased as noise.
- **Point `-c/--config` at YOUR project config**, not the one bundled under
  `vendor/` (read-only). Declare your own noise without calibrating:
  `render-loop -a mask-add --selector='.chat-widget'`.
- **`changes` is best-effort** on messy markup: a structural insertion that
  shifts positional paths makes it fall back to empty. The unified `diff` is the
  signal you can always rely on.
- **It covers rendering only**: a DB write, an export, a PDF or an API response
  stays invisible. For a critical computation, extract the function and unit-test
  it — the loop **complements** tests, it does not replace them.
- **`--expect-selector` is a per-edit guard, not a frozen regression net** (that is
  `golden-record`/`golden-verify`). `satisfied: true` only means no visible change
  leaked outside the selector — not that the edit is correct, nor that non-rendered
  effects are fine. A wrong or missing selector surfaces as `satisfied: false` with
  "matched no element", never a false green.
- **Authenticated pages**: pass the session cookie value via the
  `RENDERLOOP_COOKIE` environment variable, never as a CLI argument (it would be
  visible in `ps`).

## Working on this codebase

- **Before opening a PR**: run `composer test` and `composer phpstan`
  (PHPStan runs at **level 10** / `max`). Both must be green.
- **Keep the three contracts in sync** when you add or change an action:
  the `match` in `src/Command/HtmlCompareCommand.php`, the `capabilities`
  manifest in the same file, and the help text. The `advise` content lives in
  `adviceManifest()` — update it there and this file follows.
- Masking stays **selector-based** — never add content-regex masking (see the
  pitfall above; it is a design rule, not an oversight).
- Tests are written in **French** (method names and messages); match that style.
