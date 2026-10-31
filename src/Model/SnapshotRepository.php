<?php

declare(strict_types=1);

namespace RenderLoop\Model;

use RenderLoop\Service\UrlFolderNamer;

/**
 * Persistance des captures sur le système de fichiers, un dossier par page
 * (dérivé de l'URL), un fichier JSON par capture. Le répertoire racine est
 * injecté — jamais une constante globale en dur — pour rester testable avec un
 * dossier temporaire.
 */
final class SnapshotRepository
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;
    }

    public function save(Snapshot $snapshot): string
    {
        $dir = $this->rootDir . '/' . $snapshot->folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le dossier de snapshot : {$dir}");
        }

        $file = $dir . '/' . $snapshot->key() . '.json';
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Écriture atomique : on écrit un fichier temporaire (suffixe `.tmp`,
        // donc jamais listé par le glob `*.json`) puis on le renomme. Un
        // processus interrompu (crash, disque plein) laisse au pire un `.tmp`
        // incomplet, jamais un `.json` tronqué qui briquerait list/recheck.
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Écriture du snapshot échouée : {$tmp}");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Renommage du snapshot échoué : {$file}");
        }

        return $snapshot->id();
    }

    public function get(string $id): ?Snapshot
    {
        $position = strrpos($id, '_');
        if ($position === false) {
            return null;
        }

        $folder = (string) substr($id, 0, $position);
        $key = (string) substr($id, $position + 1);
        $file = $this->rootDir . '/' . $folder . '/' . $key . '.json';

        return $this->read($file);
    }

    /**
     * @return list<Snapshot> triés du plus récent au plus ancien
     */
    public function list(?string $folder = null): array
    {
        $pattern = $this->rootDir . '/' . ($folder ?? '*') . '/*.json';
        $snapshots = [];
        foreach (glob($pattern) ?: [] as $file) {
            $snapshot = $this->read($file);
            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, static fn (Snapshot $a, Snapshot $b): int => $b->capturedAt <=> $a->capturedAt);

        return $snapshots;
    }

    public function latest(string $url): ?Snapshot
    {
        return $this->list(UrlFolderNamer::toFolderName($url))[0] ?? null;
    }

    public function delete(string $id): bool
    {
        $snapshot = $this->get($id);
        if ($snapshot === null) {
            return false;
        }

        $file = $this->rootDir . '/' . $snapshot->folder . '/' . $snapshot->key() . '.json';

        return is_file($file) && unlink($file);
    }

    /**
     * Enregistre la capture choisie comme référence de la page (pointeur à côté
     * des snapshots, hors *.json pour ne pas être listé comme une capture).
     */
    public function setBaseline(string $folder, string $id): void
    {
        $dir = $this->rootDir . '/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier de snapshot introuvable : {$dir}");
        }
        if (file_put_contents($dir . '/_baseline', $id) === false) {
            throw new \RuntimeException("Écriture de la référence échouée : {$dir}/_baseline");
        }
    }

    public function baselineId(string $folder): ?string
    {
        $file = $this->rootDir . '/' . $folder . '/_baseline';
        if (!is_file($file)) {
            return null;
        }
        $id = file_get_contents($file);

        return ($id === false || trim($id) === '') ? null : trim($id);
    }

    private function read(string $file): ?Snapshot
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        try {
            /** @var array{url?: string, timestamp?: int, html?: string, folder?: string, tag?: ?string, git_commit?: ?string} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // On nomme le fichier fautif : sans ça, une capture corrompue
            // (écriture interrompue) faisait remonter un « Syntax error » brut
            // sans indiquer quel fichier supprimer pour s'en remettre.
            throw new \RuntimeException(
                "Snapshot illisible, JSON invalide : {$file} ({$e->getMessage()})",
                0,
                $e
            );
        }

        return Snapshot::fromArray($data);
    }
}
