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

        $this->cleanStaleTempFiles($dir);

        $file = $dir . '/' . $snapshot->key() . '.json';
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Écriture atomique : on écrit un fichier temporaire (suffixe `.tmp`,
        // donc jamais listé par le glob `*.json`) puis on le renomme. `rename()`
        // est atomique face à une interruption de processus (crash, SIGKILL) :
        // list/recheck ne voient jamais un `.json` tronqué. On compare le nombre
        // d'octets écrits à la taille attendue pour attraper une écriture
        // PARTIELLE (disque plein / ENOSPC) que `=== false` laisserait passer, et
        // on nettoie le `.tmp` dans ce cas.
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
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
        $file = $this->fileForId($id);

        return $file === null ? null : $this->read($file);
    }

    /**
     * @return list<Snapshot> triés du plus récent au plus ancien
     */
    public function list(?string $folder = null): array
    {
        $pattern = $this->rootDir . '/' . ($folder ?? '*') . '/*.json';
        $snapshots = [];
        foreach (glob($pattern) ?: [] as $file) {
            try {
                $snapshot = $this->read($file);
            } catch (\RuntimeException $e) {
                // Un seul fichier corrompu ne doit pas masquer toutes les bonnes
                // captures de toutes les pages : on le signale et on le saute.
                // get()/delete() ciblent ce fichier précis et peuvent, eux, lever
                // (get) ou le supprimer sans le décoder (delete).
                error_log('RenderLoop: ' . $e->getMessage());
                continue;
            }
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
        // On résout le chemin sans décoder le JSON : une capture corrompue reste
        // ainsi supprimable (le chemin est la cible, pas son contenu).
        $file = $this->fileForId($id);

        return $file !== null && is_file($file) && unlink($file);
    }

    /**
     * Chemin du fichier .json correspondant à un id (dossier + '_' + clé), ou
     * null si l'id est malformé. Factorisé entre get() et delete() pour que les
     * deux ciblent exactement le même fichier.
     */
    private function fileForId(string $id): ?string
    {
        $position = strrpos($id, '_');
        if ($position === false) {
            return null;
        }

        $folder = (string) substr($id, 0, $position);
        $key = (string) substr($id, $position + 1);

        // Protection anti-traversée (#24) : un id tel que « ../secret_<clé> »
        // composerait un chemin hors du dossier racine. On refuse tout composant
        // non sûr ici, donc get() ET delete() sont couverts par le même garde-fou.
        if (!self::isSafeComponent($folder) || !self::isSafeComponent($key)) {
            return null;
        }

        return $this->rootDir . '/' . $folder . '/' . $key . '.json';
    }

    /**
     * Balaie les `.tmp` orphelins qu'un save() interrompu (entre l'écriture et le
     * rename) laisse derrière lui. On ne touche qu'aux fichiers assez anciens
     * pour ne pas détruire le `.tmp` d'une écriture concurrente encore en cours.
     */
    private function cleanStaleTempFiles(string $dir): void
    {
        $now = time();
        foreach (glob($dir . '/*.tmp') ?: [] as $tmp) {
            $mtime = @filemtime($tmp);
            if ($mtime !== false && ($now - $mtime) > 3600) {
                @unlink($tmp);
            }
        }
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

    /**
     * Valide un composant de chemin (dossier ou clé) issu du découpage de l'id.
     * On ferme la traversée de répertoire — séparateur de chemin, ou segment
     * valant exactement `.`/`..` — sans sur-bloquer les noms légitimes : un
     * dossier peut légitimement contenir `..` (ex. `foo..php`) ou commencer par
     * un point (ex. `.env.php`), car `UrlFolderNamer` conserve les points. La
     * condition `basename($c) === $c` garantit qu'aucun séparateur ne subsiste,
     * ce qui protège get() ET delete() (qui délègue à get()).
     */
    private static function isSafeComponent(string $component): bool
    {
        if ($component === '' || $component === '.' || $component === '..') {
            return false;
        }

        if (strpos($component, '/') !== false || strpos($component, '\\') !== false) {
            return false;
        }

        return basename($component) === $component;
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
