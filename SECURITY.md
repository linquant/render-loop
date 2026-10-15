# Security Policy

## Supported versions

This project is pre-1.0. Security fixes are applied to the latest released
version on the `main` branch.

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities.

Instead, report them privately through GitHub's
[security advisories](https://github.com/linquant/html-compare/security/advisories/new),
or by email to the maintainers. We will acknowledge your report as quickly as we
can and keep you informed about the fix.

## Security model

HtmlCompare captures the rendered HTML of a page, and the fetch-based capture
(`CurlCaptureStrategy`) attaches the user's session cookie. This makes a
free-form target URL dangerous: it could exfiltrate authenticated content to a
third-party host (an SSRF-style issue).

The engine mitigates this by design:

- **`UrlGuard`** restricts capture to the `http`/`https` schemes and an explicit
  **allow-list of hosts** (typically the local origin). Keep that list tight.
- Redirects are **never followed**, because a cross-origin redirect would
  otherwise bypass the host allow-list.
- The session cookie is read from the `HTMLCOMPARE_COOKIE` environment variable,
  never passed as a CLI argument (which would be visible in `ps`).

When integrating the engine into your own application, you are responsible for
keeping the allow-list restricted to hosts you trust and for not exposing the
capture surface to untrusted callers.
