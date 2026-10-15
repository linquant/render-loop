<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Configuration de la normalisation : quels nœuds retirer (bruit) et à quel
 * sous-arbre restreindre la comparaison. Pilotée par un JSON versionné, mais
 * injectable en dur pour les tests.
 */
final class NormalizerConfig
{
    /**
     * @param list<string> $noiseSelectors sélecteurs CSS des nœuds à supprimer
     */
    public function __construct(
        private readonly ?string $rootSelector,
        private readonly array $noiseSelectors,
    ) {
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Config de normalisation introuvable : {$path}");
        }

        /** @var array{root_selector?: ?string, noise_selectors?: array<int, string>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * @param array{root_selector?: ?string, noise_selectors?: array<int, string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['root_selector'] ?? null,
            array_values($data['noise_selectors'] ?? []),
        );
    }

    public static function default(): self
    {
        return self::fromJsonFile(__DIR__ . '/../../config/normalizer.json');
    }

    public function rootSelector(): ?string
    {
        return $this->rootSelector;
    }

    /**
     * @return list<string>
     */
    public function noiseSelectors(): array
    {
        return $this->noiseSelectors;
    }
}
