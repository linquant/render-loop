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
    private UrlGuard $guard;

    private ?string $sessionCookie = null;

    private string $cookieName = 'PHPSESSID';

    private ?string $internalBase = null;

    private int $timeoutSeconds = 15;

    private ?string $method = null;

    /**
     * @var list<string>
     */
    private array $extraHeaders = [];

    private ?string $data = null;

    /**
     * `$method` / `$extraHeaders` / `$data` permettent de rejouer un endpoint
     * AJAX legacy (ex. un contrôleur qui n'émet le fragment que sur un POST ou
     * en présence de `X-Requested-With`). Vides, la capture reste un simple GET.
     *
     * @param list<string> $extraHeaders En-têtes HTTP supplémentaires ("Nom: valeur").
     */
    public function __construct(
        UrlGuard $guard,
        ?string $sessionCookie = null,
        string $cookieName = 'PHPSESSID',
        ?string $internalBase = null,
        int $timeoutSeconds = 15,
        ?string $method = null,
        array $extraHeaders = [],
        ?string $data = null
    ) {
        $this->guard = $guard;
        $this->sessionCookie = $sessionCookie;
        $this->cookieName = $cookieName;
        $this->internalBase = $internalBase;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->method = $method;
        $this->extraHeaders = $extraHeaders;
        $this->data = $data;
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
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $redirect = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
        // Pas de curl_close() : déprécié en PHP 8.5, le handle se libère seul.

        if (!is_string($body)) {
            throw new \RuntimeException("Capture échouée ({$url}) : {$error}");
        }
        $location = self::redirectTargetForMessage(is_string($redirect) ? $redirect : null, $this->internalBase);
        self::assertCapturableStatus($status, $url, $location);
        // N'est atteint que sur une 2xx (assertCapturableStatus a levé sinon).
        self::assertNonEmptyBody($body, $url);

        return $body;
    }

    /**
     * Un rendu n'est capturable que sur une réponse 2xx. Une 3xx (typiquement une
     * session expirée qui redirige vers la page de login) n'est pas suivie par
     * conception : la stocker reviendrait à épingler un corps vide qui ferait
     * passer recheck / golden-verify au vert à tort. On échoue donc en nommant
     * la cible de la redirection (l'en-tête Location) plutôt que de la capturer.
     *
     * @throws \RuntimeException si le statut n'est pas 2xx
     */
    public static function assertCapturableStatus(int $status, string $url, ?string $location): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status >= 300 && $status < 400) {
            $cible = $location !== null ? " vers {$location}" : '';
            throw new \RuntimeException(
                "Capture : redirection HTTP {$status}{$cible} pour {$url} ; non suivie par conception "
                . "(session expirée ?), la page n'a pas été rendue."
            );
        }

        throw new \RuntimeException("Capture : statut HTTP {$status} pour {$url}");
    }

    /**
     * La classe de bug #15 n'est pas fermée par le seul contrôle de statut : une
     * 2xx (y compris un 204 No Content) peut renvoyer un corps vide — endpoint mal
     * configuré, app qui répond 200 sans contenu. L'épingler comme golden laisse
     * recheck / golden-verify au vert à tort, exactement le symptôme de #15. On
     * refuse donc un corps vide (ou ne contenant que des blancs) sur une 2xx.
     *
     * @throws \RuntimeException si le corps est vide
     */
    public static function assertNonEmptyBody(string $body, string $url): void
    {
        if (trim($body) === '') {
            throw new \RuntimeException(
                "Capture : corps vide pour {$url} ; un snapshot vide passerait "
                . "recheck / golden-verify au vert à tort, la capture est refusée."
            );
        }
    }

    /**
     * Cible de redirection à faire figurer dans le message d'erreur. Quand la
     * requête est routée vers un hôte interne (`internalBase`), `CURLINFO_REDIRECT_URL`
     * contient l'adresse interne vue par l'app (ex. `http://web/login.php`), pas le
     * domaine d'origine du snapshot : la nommer exposerait la topologie interne et
     * égarerait l'utilisateur. On ne la révèle donc que sans routage interne.
     */
    public static function redirectTargetForMessage(?string $redirect, ?string $internalBase): ?string
    {
        if ($internalBase !== null && $internalBase !== '') {
            return null;
        }

        return ($redirect !== null && $redirect !== '') ? $redirect : null;
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
