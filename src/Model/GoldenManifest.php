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

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        $cases = [];
        $rawCases = $data['cases'] ?? [];
        if (is_array($rawCases)) {
            foreach ($rawCases as $case) {
                if (!is_array($case)) {
                    continue;
                }
                $id = $case['id'] ?? '';
                $url = $case['url'] ?? '';
                if (is_string($id) && is_string($url) && $id !== '' && $url !== '') {
                    $cases[] = ['id' => $id, 'url' => $url];
                }
            }
        }

        // `mask` peut être un scalaire malformé ("mask":".foo") : array_filter
        // lèverait un TypeError sous PHP 8, d'où le garde-fou is_array.
        $rawMask = $data['mask'] ?? [];
        $mask = is_array($rawMask) ? array_values(array_filter($rawMask, 'is_string')) : [];

        // `name` non-string ("name":123) déclencherait un TypeError sous
        // strict_types au passage au constructeur : on retombe sur le nom du
        // fichier si la valeur n'est pas une chaîne.
        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            $name = pathinfo($path, PATHINFO_FILENAME);
        }

        return new self($name, $cases, $mask, $path, $data);
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
        // les champs que ce modèle gère. On rétablit un ordre canonique
        // (name, cases, mask, puis le reste des clés d'origine) pour garder un
        // diff de manifeste propre, même quand `name` était absent à la lecture.
        $rest = $this->raw;
        unset($rest['name'], $rest['cases'], $rest['mask']);

        $data = array_merge(
            [
                'name' => $this->name,
                'cases' => $this->casesForSave(),
                'mask' => $this->mask,
            ],
            $rest
        );

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . "\n") === false) {
            throw new \RuntimeException("Écriture du manifeste échouée : {$this->path}");
        }
    }

    /**
     * Reconstruit la liste des cas pour l'écriture : on préserve les champs
     * inconnus par cas (appariés par id au tableau décodé d'origine) et on ne
     * réécrit que les clés gérées (id/url).
     *
     * @return list<array<string, mixed>>
     */
    private function casesForSave(): array
    {
        $rawCases = [];
        if (isset($this->raw['cases']) && is_array($this->raw['cases'])) {
            $rawCases = $this->raw['cases'];
        }

        $result = [];
        foreach ($this->cases as $case) {
            $extra = [];
            foreach ($rawCases as $rawCase) {
                if (is_array($rawCase) && ($rawCase['id'] ?? null) === $case['id']) {
                    $extra = $rawCase;
                    break;
                }
            }
            /** @var array<string, mixed> $merged */
            $merged = array_merge($extra, $case);
            $result[] = $merged;
        }

        return $result;
    }
}
