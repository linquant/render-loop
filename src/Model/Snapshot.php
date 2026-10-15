<?php

declare(strict_types=1);

namespace RenderLoop\Model;

use RenderLoop\Service\UrlFolderNamer;

/**
 * Une capture : le HTML BRUT d'une page à un instant donné, plus ses
 * métadonnées. On stocke le brut, pas le normalisé — la normalisation est
 * ré-appliquée au moment de comparer, pour qu'un changement de config de
 * masquage profite aussi aux anciennes captures.
 *
 * La clé de stockage est horodatée à la microseconde (`capturedAt`) : deux
 * captures rapprochées — le cas capture → recontrôle — ne doivent pas tomber
 * dans la même seconde et s'écraser.
 */
final class Snapshot
{
    /** Secondes Unix, pour l'affichage. */
    public readonly int $timestamp;

    public function __construct(
        public readonly string $url,
        public readonly float $capturedAt,
        public readonly string $html,
        public readonly string $folder,
        public readonly ?string $tag = null,
        public readonly ?string $gitCommit = null,
    ) {
        $this->timestamp = (int) $capturedAt;
    }

    public static function create(string $url, string $html, ?string $tag = null, ?string $gitCommit = null): self
    {
        return new self($url, microtime(true), $html, UrlFolderNamer::toFolderName($url), $tag, $gitCommit);
    }

    /** Clé unique au sein d'un dossier : nom de fichier et composante de l'id. */
    public function key(): string
    {
        return number_format($this->capturedAt, 4, '.', '');
    }

    public function id(): string
    {
        return $this->folder . '_' . $this->key();
    }

    /**
     * @return array{url: string, captured_at: float, timestamp: int, html: string, folder: string, tag: ?string, git_commit: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'captured_at' => $this->capturedAt,
            'timestamp' => $this->timestamp,
            'html' => $this->html,
            'folder' => $this->folder,
            'tag' => $this->tag,
            'git_commit' => $this->gitCommit,
        ];
    }

    /**
     * @param array{url?: string, captured_at?: float, timestamp?: int, html?: string, folder?: string, tag?: ?string, git_commit?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['url'] ?? '',
            $data['captured_at'] ?? (float) ($data['timestamp'] ?? 0),
            $data['html'] ?? '',
            $data['folder'] ?? '',
            $data['tag'] ?? null,
            $data['git_commit'] ?? null,
        );
    }
}
