<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Barrière anti-SSRF : la capture attache le cookie de session de l'utilisateur,
 * donc une URL libre permettrait d'exfiltrer ce qu'il voit vers un domaine tiers.
 * Seuls un schéma http(s) et un hôte de la liste blanche (origine locale) passent.
 */
final class UrlGuard
{
    /**
     * @var list<string>
     */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts hôtes autorisés (comparaison insensible à la casse)
     */
    public function __construct(array $allowedHosts)
    {
        $this->allowedHosts = $allowedHosts;
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $allowed = array_map('strtolower', $this->allowedHosts);

        return in_array(strtolower($parts['host']), $allowed, true);
    }

    public function assertAllowed(string $url): void
    {
        if (!$this->isAllowed($url)) {
            throw new \InvalidArgumentException("URL non autorisée (origine locale uniquement) : {$url}");
        }
    }
}
