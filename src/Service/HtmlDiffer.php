<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Produit un diff unifié entre deux HTML déjà normalisés, plafonné en nombre de
 * hunks, accompagné d'un résumé. On s'appuie sur sebastian/diff plutôt que de
 * réécrire un algorithme de diff.
 */
final class HtmlDiffer
{
    private int $maxHunks = 20;

    private ChangeExtractor $changeExtractor;

    public function __construct(
        int $maxHunks = 20,
        ?ChangeExtractor $changeExtractor = null
    ) {
        $this->maxHunks = $maxHunks;
        $this->changeExtractor = $changeExtractor ?? new ChangeExtractor();
    }

    public function diff(string $normalizedBefore, string $normalizedAfter): DiffResult
    {
        $differ = new Differ(new UnifiedDiffOutputBuilder('', true));
        $raw = $differ->diff($normalizedBefore, $normalizedAfter);

        $lines = $raw === '' ? [] : explode("\n", $raw);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $added = 0;
        $removed = 0;
        $hunks = 0;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            switch ($line[0]) {
                case '@':
                    $hunks++;
                    break;
                case '+':
                    $added++;
                    break;
                case '-':
                    $removed++;
                    break;
            }
        }

        [$cappedLines, $truncated] = $this->capHunks($lines);

        $tags = $this->extractTagsTouched($lines);

        // Les changements localisés n'ont de sens que s'il y a un diff ; sur un
        // rendu identique on évite de reparser deux fois le DOM pour rien.
        $changes = ($added > 0 || $removed > 0)
            ? $this->changeExtractor->extract($normalizedBefore, $normalizedAfter)
            : [];

        return new DiffResult(
            implode("\n", $cappedLines),
            $added,
            $removed,
            $hunks,
            $truncated,
            $tags,
            $changes,
        );
    }

    /**
     * @param list<string> $lines
     * @return array{0: list<string>, 1: bool}
     */
    private function capHunks(array $lines): array
    {
        $kept = [];
        $seen = 0;
        $truncated = false;

        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '@') {
                $seen++;
                if ($seen > $this->maxHunks) {
                    $truncated = true;
                    break;
                }
            }
            $kept[] = $line;
        }

        return [$kept, $truncated];
    }

    /**
     * Balises réellement touchées. Les lignes `-`/`+` d'un même bloc sont
     * appariées puis réduites à leur delta (on retire préfixe et suffixe communs) :
     * si seul un texte change au milieu d'une ligne qui porte plusieurs balises,
     * on ne remonte pas toutes ces balises, seulement celles du fragment modifié.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function extractTagsTouched(array $lines): array
    {
        $tags = [];
        /** @var list<string> $dels */
        $dels = [];
        /** @var list<string> $adds */
        $adds = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === '-') {
                $dels[] = (string) substr($line, 1);
            } elseif ($line[0] === '+') {
                $adds[] = (string) substr($line, 1);
            } else {
                $this->collectBlockTags($dels, $adds, $tags);
                $dels = [];
                $adds = [];
            }
        }
        $this->collectBlockTags($dels, $adds, $tags);

        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    /**
     * Apparie les lignes retirées/ajoutées d'un bloc et remonte les balises de
     * leur delta (ou de la ligne entière quand elle n'est pas appariée).
     *
     * @param list<string> $dels
     * @param list<string> $adds
     * @param list<string> $tags
     */
    private function collectBlockTags(array $dels, array $adds, array &$tags): void
    {
        $count = max(count($dels), count($adds));
        for ($k = 0; $k < $count; $k++) {
            $del = $dels[$k] ?? null;
            $add = $adds[$k] ?? null;
            if ($del !== null && $add !== null) {
                [$resDel, $resAdd] = $this->residual($del, $add);
                $this->collectTags($resDel, $tags);
                $this->collectTags($resAdd, $tags);
            } elseif ($del !== null) {
                $this->collectTags($del, $tags);
            } elseif ($add !== null) {
                $this->collectTags($add, $tags);
            }
        }
    }

    /**
     * Retire le préfixe et le suffixe communs des deux lignes, ne gardant que le
     * fragment qui diffère de part et d'autre.
     *
     * @return array{0: string, 1: string}
     */
    private function residual(string $a, string $b): array
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        $min = min($lenA, $lenB);

        $prefix = 0;
        while ($prefix < $min && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < ($lenA - $prefix) && $suffix < ($lenB - $prefix)
            && $a[$lenA - 1 - $suffix] === $b[$lenB - 1 - $suffix]) {
            $suffix++;
        }

        return [
            (string) substr($a, $prefix, $lenA - $prefix - $suffix),
            (string) substr($b, $prefix, $lenB - $prefix - $suffix),
        ];
    }

    /**
     * @param list<string> $tags
     */
    private function collectTags(string $fragment, array &$tags): void
    {
        if (preg_match_all('/<\/?([a-z][a-z0-9]*)/i', $fragment, $matches) === false) {
            return;
        }
        foreach ($matches[1] as $tag) {
            $tags[] = strtolower($tag);
        }
    }
}
