<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Résultat d'une comparaison : le diff unifié (plafonné) et un résumé compact
 * pensé pour un lecteur à budget de tokens limité (une IA) — on lit le résumé,
 * on ne déroule le diff que si besoin.
 */
final class DiffResult
{
    public string $unifiedDiff;

    public int $linesAdded;

    public int $linesRemoved;

    public int $hunks;

    public bool $truncated;

    /**
     * @var list<string>
     */
    public array $tagsTouched;

    /**
     * @var list<Change>
     */
    public array $changes = [];

    /**
     * @param list<string> $tagsTouched noms de balises apparaissant dans les lignes modifiées
     * @param list<Change> $changes changements localisés (sélecteur + ancre texte + avant/après)
     */
    public function __construct(
        string $unifiedDiff,
        int $linesAdded,
        int $linesRemoved,
        int $hunks,
        bool $truncated,
        array $tagsTouched,
        array $changes = []
    ) {
        $this->unifiedDiff = $unifiedDiff;
        $this->linesAdded = $linesAdded;
        $this->linesRemoved = $linesRemoved;
        $this->hunks = $hunks;
        $this->truncated = $truncated;
        $this->tagsTouched = $tagsTouched;
        $this->changes = $changes;
    }

    public function isIdentical(): bool
    {
        return $this->linesAdded === 0 && $this->linesRemoved === 0;
    }

    /**
     * @return array{lines_added: int, lines_removed: int, hunks: int, truncated: bool, tags_touched: list<string>, changes: list<array{kind: string, selector: string, context: ?string, before: ?string, after: ?string}>}
     */
    public function summary(): array
    {
        return [
            'lines_added' => $this->linesAdded,
            'lines_removed' => $this->linesRemoved,
            'hunks' => $this->hunks,
            'truncated' => $this->truncated,
            'tags_touched' => $this->tagsTouched,
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
        ];
    }
}
