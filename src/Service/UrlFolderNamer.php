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
 * Un `.php` ne peut pas se réduire à son seul basename non plus : `admin/index.php`
 * et `shop/index.php` (ou le même basename sur deux hôtes) partageraient le même
 * dossier et donc la même baseline. On garde le basename lisible mais on lui
 * accole un hash court de `host + path` pour rendre le dossier unique par URL.
 */
final class UrlFolderNamer
{
    public static function toFolderName(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            $parsed = [];
        }

        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? '';

        if ($path !== '' && preg_match('/\.php$/i', $path) === 1) {
            $base = basename($path) . '_' . substr(sha1($host . $path), 0, 10);
        } else {
            $base = trim($host . '_' . trim($path, '/'), '_');
        }
        if ($base === '') {
            $base = 'page';
        }

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
