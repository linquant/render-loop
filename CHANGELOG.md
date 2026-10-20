# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Minimum PHP version lowered from 8.2 to 7.4. The code now avoids PHP 8
  syntax; Composer selects Symfony 5.4 and `sebastian/diff` 4 on 7.4, and the
  current versions on PHP 8.2+.
- Public DTO properties are no longer `readonly` (PHP 7.4 has no readonly
  properties). Treat them as read-only; they are only assigned by constructors.

### Added

- Initial public release.
- Semantic HTML diff engine with selector-based noise masking.
- Capture strategies: `CurlCaptureStrategy` (server-rendered, host-bounded by
  `UrlGuard`) and `PushedHtmlStrategy` (push pre-rendered HTML for JS/SPA pages).
- Automatic noise calibration (`calibrate`).
- Golden-master / characterization-testing mode (`golden-record`,
  `golden-verify`).
- CLI (`bin/render-loop`) with `table` and `json` output for human and agent use.
- `capabilities` action: a machine-readable contract of every action (`-f json`) —
  required options, JSON output shape and exit-code semantics — so an agent can
  discover the CLI without parsing the help text.
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

### Fixed

- CLI failures are written to stderr instead of stdout. With `-f json`, stdout
  now stays empty on error, so a caller never has to strip a human-readable
  message before decoding it.

[Unreleased]: https://github.com/linquant/render-loop/commits/main
