<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Vérifie qu'un changement de rendu reste CONFINÉ aux sélecteurs déclarés.
 *
 * Le principe réutilise la machinerie de masquage, sans parser `changes[]` (qui
 * est best-effort) : on ajoute TEMPORAIREMENT les sélecteurs attendus au masque de
 * bruit, on re-normalise et on re-diffe. Si ce diff masqué est vide, tout le
 * changement était dans la portée → confiné. S'il reste quelque chose, c'est la
 * fuite hors portée — et ce diff masqué EST la preuve à montrer. On s'appuie ainsi
 * sur le diff unifié (le plancher fiable), et le masquage reste par SÉLECTEUR.
 */
final class ScopeAssertionChecker
{
    private NormalizerConfig $config;

    private HtmlDiffer $differ;

    public function __construct(
        NormalizerConfig $config,
        HtmlDiffer $differ
    ) {
        $this->config = $config;
        $this->differ = $differ;
    }

    /**
     * @param list<string> $expectedSelectors
     */
    public function check(string $before, string $after, array $expectedSelectors): ScopeAssertion
    {
        // La validation des sélecteurs attendus vit à la frontière CLI
        // (HtmlCompareCommand), avant la capture, où le nom `--expect-selector`
        // est connu — inutile de la refaire ici. Un sélecteur invalide passé en
        // direct reste néanmoins rejeté par le masquage (removeMatching convertit
        // via CssSelectorValidator), donc check() ne peut pas se taire dessus.
        $base = new HtmlNormalizer($this->config);
        $masked = new HtmlNormalizer($this->config->withAdditionalNoise($expectedSelectors));

        $baseBefore = $base->normalize($before);
        $baseAfter = $base->normalize($after);
        $maskedBefore = $masked->normalize($before);
        $maskedAfter = $masked->normalize($after);

        $full = $this->differ->diff($baseBefore, $baseAfter);
        $outOfScope = $this->differ->diff($maskedBefore, $maskedAfter);

        $satisfied = $outOfScope->isIdentical();

        // Comparaison par NOMBRE de lignes modifiées, pas par chaîne : retirer un
        // sous-arbre décale les numéros de ligne des hunks mais pas le compte des
        // lignes +/-. Si le diff complet compte plus de lignes que le diff hors
        // portée, c'est que la portée elle-même a changé.
        $insideScopeChanged = ($full->linesAdded + $full->linesRemoved)
            > ($outOfScope->linesAdded + $outOfScope->linesRemoved);

        // Le masque n'a rien retiré des deux côtés → le sélecteur n'a matché aucun
        // nœud (typo de l'agent, zone absente de la page) : on le signale au lieu de
        // laisser croire à une fuite ordinaire.
        $matchedNothing = $baseBefore === $maskedBefore && $baseAfter === $maskedAfter;

        return new ScopeAssertion(
            $expectedSelectors,
            $satisfied,
            $insideScopeChanged,
            $this->reason($satisfied, $insideScopeChanged, $matchedNothing, $outOfScope),
            $outOfScope,
        );
    }

    private function reason(bool $satisfied, bool $insideScopeChanged, bool $matchedNothing, DiffResult $outOfScope): string
    {
        if ($satisfied) {
            return $insideScopeChanged
                ? 'all changes confined to the expected scope'
                : 'nothing changed (expected scope not affected)';
        }

        if ($matchedNothing) {
            return 'the expected selector matched no element; the whole change is out of scope';
        }

        $count = count($outOfScope->changes);

        // `changes` peut retomber à vide (best-effort) alors que le diff est non vide.
        return $count > 0
            ? sprintf('%d change(s) outside the expected scope', $count)
            : 'changes outside the expected scope';
    }
}
