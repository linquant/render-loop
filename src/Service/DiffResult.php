<?php

declare(strict_types=1);

namespace HtmlCompare\Service;

/**
 * Résultat d'une comparaison : le diff unifié (plafonné) et un résumé compact
 * pensé pour un lecteur à budget de tokens limité (une IA) — on lit le résumé,
 * on ne déroule le diff que si besoin.
 */
final class DiffResult
{
    /**
     * @param list<string> $tagsTouched noms de balises apparaissant dans les lignes modifiées
     */
    public function __construct(
        public readonly string $unifiedDiff,
        public readonly int $linesAdded,
        public readonly int $linesRemoved,
        public readonly int $hunks,
        public readonly bool $truncated,
        public readonly array $tagsTouched,
    ) {
    }

    public function isIdentical(): bool
    {
        return $this->linesAdded === 0 && $this->linesRemoved === 0;
    }

    /**
     * @return array{lines_added: int, lines_removed: int, hunks: int, truncated: bool, tags_touched: list<string>}
     */
    public function summary(): array
    {
        return [
            'lines_added' => $this->linesAdded,
            'lines_removed' => $this->linesRemoved,
            'hunks' => $this->hunks,
            'truncated' => $this->truncated,
            'tags_touched' => $this->tagsTouched,
        ];
    }
}
