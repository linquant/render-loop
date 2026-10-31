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
 * La clé de stockage combine l'horodatage (`capturedAt`) et un hash court du
 * contenu : deux captures rapprochées — le cas capture → recontrôle — ne doivent
 * pas tomber sur la même clé et s'écraser (voir {@see self::key()}).
 */
final class Snapshot
{
    public string $url;

    public float $capturedAt;

    public string $html;

    public string $folder;

    public ?string $tag = null;

    public ?string $gitCommit = null;

    /** Secondes Unix, pour l'affichage. */
    public int $timestamp;

    public function __construct(
        string $url,
        float $capturedAt,
        string $html,
        string $folder,
        ?string $tag = null,
        ?string $gitCommit = null
    ) {
        $this->url = $url;
        $this->capturedAt = $capturedAt;
        $this->html = $html;
        $this->folder = $folder;
        $this->tag = $tag;
        $this->gitCommit = $gitCommit;
        $this->timestamp = (int) $capturedAt;
    }

    public static function create(string $url, string $html, ?string $tag = null, ?string $gitCommit = null): self
    {
        return new self($url, microtime(true), $html, UrlFolderNamer::toFolderName($url), $tag, $gitCommit);
    }

    /**
     * Clé unique au sein d'un dossier : nom de fichier et composante de l'id.
     *
     * L'horodatage seul (4 décimales = 0,1 ms) ne suffit pas : deux captures en
     * mémoire très rapprochées — le cas capture → recontrôle — peuvent tomber sur
     * le même instant, et la seconde ÉCRASERAIT alors le fichier de la première
     * (une baseline perdue au profit d'un recontrôle). On suffixe donc un hash
     * court du contenu et du tag : deux captures au même instant mais de contenu
     * ou de rôle différents obtiennent des clés distinctes. Le suffixe est
     * déterministe (dérivé de l'état de la capture), donc la clé reste stable
     * d'un appel à l'autre — indispensable pour relocaliser le fichier par son id.
     * Pas de « _ » dans le suffixe : l'id se découpe au dernier « _ ».
     */
    public function key(): string
    {
        $horodatage = number_format($this->capturedAt, 4, '.', '');
        $empreinte = substr(sha1($this->html . "\0" . ($this->tag ?? '')), 0, 8);

        return $horodatage . '-' . $empreinte;
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
