<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Ajoute des sélecteurs de bruit à un fichier de config de normalisation.
 *
 * Deux usages : `calibrate --apply` y verse les sélecteurs découverts, et un
 * agent peut y déclarer les SIENS (`mask-add`) — un widget de chat, une bannière
 * de cookies, tout ce qu'il sait être du bruit sans attendre la calibration.
 *
 * Idempotent : un sélecteur déjà présent n'est pas dupliqué, l'ordre existant est
 * préservé, et seuls les sélecteurs réellement ajoutés sont renvoyés.
 */
final class NoiseConfigWriter
{
    /**
     * @param list<string> $candidates
     * @return list<string> les sélecteurs réellement ajoutés (hors doublons)
     */
    public function append(string $path, array $candidates): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config de normalisation introuvable : {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Config de normalisation illisible : {$path}");
        }

        /** @var array{root_selector?: ?string, noise_selectors?: array<int, string>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $selectors = array_values($data['noise_selectors'] ?? []);

        $added = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || in_array($candidate, $selectors, true)) {
                continue;
            }
            $selectors[] = $candidate;
            $added[] = $candidate;
        }

        if ($added === []) {
            return [];
        }

        $data['noise_selectors'] = $selectors;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Impossible de sérialiser la config : {$path}");
        }
        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException("Écriture de la config échouée : {$path}");
        }

        return $added;
    }
}
