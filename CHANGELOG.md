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
- CLI (`bin/html-compare`) with `table` and `json` output for human and agent use.
- Framework-agnostic web UI example under `examples/web`.

[Unreleased]: https://github.com/johann-nowacki/html-compare/commits/main
