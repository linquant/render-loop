# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial public release.
- Semantic HTML diff engine with selector-based noise masking.
- Capture strategies: `CurlCaptureStrategy` (server-rendered, host-bounded by
  `UrlGuard`) and `PushedHtmlStrategy` (push pre-rendered HTML for JS/SPA pages).
- Automatic noise calibration (`calibrate`).
- Golden-master / characterization-testing mode (`golden-record`,
  `golden-verify`).
- CLI (`bin/render-loop`) with `table` and `json` output for human and agent use.
- `mask-add` action: an agent (or a human) can add its own noise selectors to the
  config without calibration — `--selector` is repeatable and idempotent.
- `calibrate --apply` writes the discovered selectors straight into the config,
  and `--samples=N` captures more than twice to catch slower-moving noise.
- `-c/--config` option: point normalization at a config file in your own project
  rather than the one bundled under `vendor/`. Golden master honors it too.
- `changes` in the JSON summary: a localized, semantic list of what changed —
  each entry carries a best-effort CSS `selector`, a `context` anchor (the nearest
  heading/label, readable even on legacy markup without ids or classes), a `kind`
  (`text`/`added`/`removed`) and the `before`/`after` values. The unified diff
  stays the floor; `changes` falls back to empty when positional paths cascade.
- `--exit-code` flag: `recheck` and `compare` exit non-zero when the diff is
  non-empty (like `git diff --exit-code`), so an agent can branch on the status
  code instead of parsing the JSON summary.
- Framework-agnostic web UI example under `examples/web`.

[Unreleased]: https://github.com/linquant/render-loop/commits/main
