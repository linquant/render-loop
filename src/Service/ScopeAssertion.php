<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Verdict d'une assertion de portée : l'agent a DÉCLARÉ qu'un edit ne devait
 * toucher que `expectedSelectors`, on répond par un oui/non et, en cas d'échec,
 * par le diff de ce qui a fuité HORS de cette portée.
 *
 * Ce n'est pas un filet anti-régression figé (ça, c'est le golden master) : c'est
 * un garde-fou de rayon d'impact, éphémère, pour un seul edit. `satisfied` ne
 * garantit que l'absence de changement de RENDU hors portée — ni la correction de
 * l'edit, ni les effets non-rendus (DB, export, API).
 */
final class ScopeAssertion
{
    /**
     * @var list<string>
     */
    public array $expectedSelectors;

    public bool $satisfied;

    public bool $insideScopeChanged;

    public string $reason;

    /**
     * @var DiffResult
     */
    public DiffResult $outOfScope;

    /**
     * @param list<string> $expectedSelectors sélecteurs où le changement est autorisé
     * @param DiffResult $outOfScope diff de ce qui a changé EN DEHORS de la portée (vide si satisfait)
     */
    public function __construct(
        array $expectedSelectors,
        bool $satisfied,
        bool $insideScopeChanged,
        string $reason,
        DiffResult $outOfScope
    ) {
        $this->expectedSelectors = $expectedSelectors;
        $this->satisfied = $satisfied;
        $this->insideScopeChanged = $insideScopeChanged;
        $this->reason = $reason;
        $this->outOfScope = $outOfScope;
    }

    /**
     * @return array{expected_selectors: list<string>, satisfied: bool, inside_scope_changed: bool, reason: string, out_of_scope: array{lines_added: int, lines_removed: int, hunks: int, truncated: bool, tags_touched: list<string>, changes: list<array{kind: string, selector: string, context: ?string, before: ?string, after: ?string}>, diff: string}}
     */
    public function toArray(): array
    {
        $outOfScope = $this->outOfScope->summary();
        $outOfScope['diff'] = $this->outOfScope->unifiedDiff;

        return [
            'expected_selectors' => $this->expectedSelectors,
            'satisfied' => $this->satisfied,
            'inside_scope_changed' => $this->insideScopeChanged,
            'reason' => $this->reason,
            'out_of_scope' => $outOfScope,
        ];
    }
}
