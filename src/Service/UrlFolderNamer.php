<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Transforme une URL (query string comprise) en nom de dossier de stockage
 * déterministe et unique.
 *
 * Le legacy réduisait un script `.php` à son seul basename : `fiche.php?id=1`
 * et `fiche.php?id=2` atterrissaient dans le même dossier et s'écrasaient.
 * Ici la query distingue les dossiers, et l'ordre des paramètres est normalisé
 * pour que `?a=1&b=2` et `?b=2&a=1` désignent la même page.
 *
 * Un seul schéma de nommage : un préfixe lisible (le basename pour un script
 * `.php`, sinon `host_path`) suivi d'un hash court de l'URL normalisée complète
 * — schéma + host + port + path. Ce hash rend le dossier unique par URL :
 * `admin/index.php` et `shop/index.php`, le même basename sur deux hôtes, mais
 * aussi `http://localhost:8080/a.php` vs `:8081/a.php` et `http://` vs `https://`,
 * tombent chacun dans un dossier distinct (donc une baseline distincte).
 */
final class UrlFolderNamer
{
    public static function toFolderName(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            $parsed = [];
        }

        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? (string) $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? '';

        // Préfixe lisible : le basename pour un script, sinon host + chemin.
        if ($path !== '' && preg_match('/\.php$/i', $path) === 1) {
            $prefix = basename($path);
        } else {
            $prefix = trim($host . '_' . trim($path, '/'), '_');
        }
        if ($prefix === '') {
            $prefix = 'page';
        }

        // Hash d'unicité sur l'URL normalisée complète (schéma + host + port + path) :
        // deux schémas, ports ou hôtes distincts ne partagent jamais le même dossier.
        $normalized = $scheme . '://' . $host . ($port !== '' ? ':' . $port : '') . $path;
        $base = $prefix . '_' . substr(sha1($normalized), 0, 10);

        $suffix = '';
        if ($query !== '') {
            parse_str($query, $params);
            ksort($params);
            $suffix = '_' . substr(sha1(http_build_query($params)), 0, 10);
        }

        $name = $base . $suffix;

        return preg_replace('/[^a-z0-9_\-.]/i', '_', $name) ?? $name;
    }
}
