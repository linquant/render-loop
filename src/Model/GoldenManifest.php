<?php

declare(strict_types=1);

namespace RenderLoop\Model;

/**
 * Déclaration versionnée d'une suite golden master : les cas (une URL = une
 * entrée représentative du calcul/de la feature à épingler) et les sélecteurs de
 * bruit à masquer. Le masque est enrichi automatiquement à l'enregistrement
 * (calibration) puis réécrit dans le fichier pour être committé et relu.
 */
final class GoldenManifest
{
    private string $name;

    /**
     * @var list<array{id: string, url: string}>
     */
    private array $cases;

    /**
     * @var list<string>
     */
    private array $mask;

    private string $path;

    /**
     * Tableau décodé d'origine, conservé tel quel pour que les champs inconnus
     * (description, $schema, options futures par cas…) survivent à un aller-retour
     * record/relecture : save() ne réécrit que les champs qu'il gère.
     *
     * @var array<string, mixed>
     */
    private array $raw;

    /**
     * @param list<array{id: string, url: string}> $cases
     * @param list<string> $mask
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $name,
        array $cases,
        array $mask,
        string $path,
        array $raw = []
    ) {
        $this->name = $name;
        $this->cases = $cases;
        $this->mask = $mask;
        $this->path = $path;
        $this->raw = $raw;
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Manifeste golden introuvable : {$path}");
        }

        /** @var array{name?: string, cases?: list<array{id?: string, url?: string}>, mask?: list<string>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            $data = [];
        }

        $cases = [];
        foreach ($data['cases'] ?? [] as $case) {
            $id = $case['id'] ?? '';
            $url = $case['url'] ?? '';
            if ($id !== '' && $url !== '') {
                $cases[] = ['id' => $id, 'url' => $url];
            }
        }

        $mask = array_values(array_filter($data['mask'] ?? [], 'is_string'));

        return new self($data['name'] ?? pathinfo($path, PATHINFO_FILENAME), $cases, $mask, $path, $data);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<array{id: string, url: string}>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    /**
     * @return list<string>
     */
    public function mask(): array
    {
        return $this->mask;
    }

    /**
     * @param list<string> $selectors
     */
    public function addMask(array $selectors): void
    {
        $this->mask = array_values(array_unique(array_merge($this->mask, $selectors)));
    }

    public function save(): void
    {
        // On repart du tableau décodé pour préserver les champs inconnus
        // (description, $schema, options futures par cas…) et on ne réécrit que
        // les champs que ce modèle gère.
        $data = $this->raw;
        $data['name'] = $this->name;
        $data['cases'] = $this->cases;
        $data['mask'] = $this->mask;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n") === false) {
            throw new \RuntimeException("Écriture du manifeste échouée : {$this->path}");
        }
    }
}
