<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Capture par curl, pour les pages rendues côté serveur. Le cookie de session
 * (optionnel) permet d'atteindre les pages authentifiées. Les redirections sont
 * désactivées : suivre une redirection cross-origin reviendrait à contourner le
 * garde SSRF.
 *
 * `internalBase` : adresse réellement joignable depuis le processus (ex. le
 * service `web` du compose) quand l'URL demandée pointe sur le même domaine que
 * l'app mais via un port publié côté hôte, injoignable depuis le conteneur php.
 * L'URL d'origine reste l'identité du snapshot ; seul le fetch est routé, et
 * l'en-tête Host d'origine est préservé pour que l'app réponde normalement.
 */
final class CurlCaptureStrategy implements CaptureStrategy
{
    /**
     * `$method` / `$extraHeaders` / `$data` permettent de rejouer un endpoint
     * AJAX legacy (ex. un contrôleur qui n'émet le fragment que sur un POST ou
     * en présence de `X-Requested-With`). Vides, la capture reste un simple GET.
     *
     * @param list<string> $extraHeaders En-têtes HTTP supplémentaires ("Nom: valeur").
     */
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly ?string $sessionCookie = null,
        private readonly string $cookieName = 'PHPSESSID',
        private readonly ?string $internalBase = null,
        private readonly int $timeoutSeconds = 15,
        private readonly ?string $method = null,
        private readonly array $extraHeaders = [],
        private readonly ?string $data = null,
    ) {
    }

    public function capture(string $url): string
    {
        $this->guard->assertAllowed($url);

        $target = self::rewriteTarget($url, $this->internalBase);
        $handle = curl_init($target);
        if ($handle === false) {
            throw new \RuntimeException("Initialisation curl impossible : {$url}");
        }

        curl_setopt_array($handle, $this->buildRequestOptions($this->originalHostHeader($url)));

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // Pas de curl_close() : déprécié en PHP 8.5, le handle se libère seul.

        if (!is_string($body)) {
            throw new \RuntimeException("Capture échouée ({$url}) : {$error}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("Capture : statut HTTP {$status} pour {$url}");
        }

        return $body;
    }

    /**
     * Construit le tableau d'options curl (hors URL cible). Isolé de `curl_exec`
     * pour être testable sans réseau, comme {@see rewriteTarget}.
     *
     * @param ?string $hostHeader En-tête Host d'origine à préserver quand la
     *                            requête est routée vers `internalBase`.
     * @return array<int, mixed>
     */
    public function buildRequestOptions(?string $hostHeader): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($this->sessionCookie !== null) {
            $options[CURLOPT_COOKIE] = $this->cookieName . '=' . $this->sessionCookie;
        }

        // --data seul suffit à basculer la requête en POST (comportement curl usuel).
        $method = $this->method ?? ($this->data !== null ? 'POST' : null);
        if ($method !== null) {
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        }
        if ($this->data !== null) {
            $options[CURLOPT_POSTFIELDS] = $this->data;
        }

        // Fusion : le Host interne (routage internalBase) cohabite avec les
        // en-têtes custom au lieu d'être écrasé par eux.
        $headers = $this->extraHeaders;
        if ($this->internalBase !== null && $hostHeader !== null) {
            array_unshift($headers, 'Host: ' . $hostHeader);
        }
        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        return $options;
    }

    /**
     * Reconstruit l'URL à aller chercher : le chemin+query de l'URL demandée,
     * porté par l'hôte interne joignable. Sans hôte interne, l'URL est inchangée.
     */
    public static function rewriteTarget(string $url, ?string $internalBase): string
    {
        if ($internalBase === null || $internalBase === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return rtrim($internalBase, '/') . $path . $query;
    }

    private function originalHostHeader(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $host . ':' . $port : $host;
    }
}
