# Contributing to RenderLoop

Thanks for taking the time to contribute! This project is small, focused, and
test-driven — contributions that keep it that way are very welcome.

## Getting started

```bash
git clone https://github.com/linquant/render-loop.git
cd render-loop
composer install
```

Run the full check suite locally before pushing:

```bash
composer test      # PHPUnit
composer phpstan   # PHPStan, level 8
```

Both must be green. CI runs the same commands on PHP 7.4, 8.0, 8.1, 8.2,
8.3, 8.4 and 8.5.

## Ground rules

- **Add a test** for any behaviour change or bug fix. The engine is pure and
  deterministic, so almost everything is unit-testable without a network.
- **Keep PHPStan at level 8.** No new errors, no suppressions without a reason.
- **Match the surrounding style** — strict types, constructor injection, small
  single-purpose services. There is no runtime magic; favour explicit code.
- **Masking stays by selector, never by regex on content.** This is a core
  invariant: a real value change (e.g. a price dropping to `0.00`) must always
  remain visible in the diff. Do not add content-based noise filtering.
- **Respect the security boundary.** Capture is bounded by `UrlGuard` to an
  allow-list of hosts and never follows redirects. Any change touching capture
  must preserve the anti-SSRF guarantee.

## Submitting a change

1. Fork and create a branch (`git checkout -b fix/short-description`).
2. Make the change with its test(s).
3. Ensure `composer test` and `composer phpstan` pass.
4. Add a note under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
5. Open a pull request describing **what** changed and **why**.

## Reporting bugs & security issues

- Regular bugs: open an issue with a minimal reproduction (ideally a failing
  test or a small HTML snippet).
- Security vulnerabilities: please follow [SECURITY.md](SECURITY.md) — do **not**
  open a public issue.

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
