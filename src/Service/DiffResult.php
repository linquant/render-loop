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
    /**
     * @param list<string> $tagsTouched noms de balises apparaissant dans les lignes modifiées
     * @param list<Change> $changes changements localisés (sélecteur + ancre texte + avant/après)
     */
    public function __construct(
        public readonly string $unifiedDiff,
        public readonly int $linesAdded,
        public readonly int $linesRemoved,
        public readonly int $hunks,
        public readonly bool $truncated,
        public readonly array $tagsTouched,
        public readonly array $changes = [],
    ) {
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
