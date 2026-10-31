<?php

declare(strict_types=1);

/**
 * SSRF allowlist helper for the web example, extracted into its own includable
 * file so a test (or a host app) can reach it without executing the whole
 * example shell (which would run the JSON router and declare every example
 * function into the process).
 */

if (!function_exists('renderloop_allowed_hosts')) {
    /**
     * Hosts the capture engine is allowed to reach. These are NEVER derived from
     * the client-supplied Host header ($_SERVER['HTTP_HOST']): CurlCaptureStrategy
     * attaches the visitor's session cookie to any allowed host, so trusting
     * HTTP_HOST would let a request with `Host: attacker.example` authorize a
     * capture of that domain WITH the cookie attached — SSRF plus cookie
     * exfiltration, i.e. exactly what UrlGuard exists to prevent.
     *
     * A configured value (RENDERLOOP_ALLOWED_HOSTS, comma-separated) EXTENDS the
     * loopback defaults rather than replacing them: `localhost` and `127.0.0.1`
     * stay reachable so the documented `http://localhost:8000/` run keeps working.
     *
     * @return list<string>
     */
    function renderloop_allowed_hosts(?string $configured): array
    {
        $hosts = ['localhost', '127.0.0.1'];
        if ($configured === null || trim($configured) === '') {
            return $hosts;
        }

        foreach (explode(',', $configured) as $host) {
            $host = trim($host);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
