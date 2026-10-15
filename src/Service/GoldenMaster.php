<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use RenderLoop\Model\GoldenManifest;

/**
 * Golden master (approval testing) pour pages legacy sans tests : épingle la
 * sortie rendue ACTUELLE d'un jeu d'entrées représentatives, puis vérifie après
 * refactoring qu'elle n'a pas bougé.
 *
 * - `record` : pour chaque cas, calibre le bruit (2 captures identiques), masque,
 *   capture, et stocke la sortie normalisée comme référence committable.
 * - `verify` : re-capture chaque cas et compare à sa référence.
 *
 * Caractérisation, pas spécification : on ne prétend pas que la sortie est
 * *correcte*, on garantit qu'elle n'a pas changé **par accident**.
 */
final class GoldenMaster
{
    public function __construct(
        private readonly HtmlDiffer $differ,
        private readonly string $goldenRootDir,
        private readonly int $calibrationDelayMicros = 0,
        private readonly ?NormalizerConfig $baseConfig = null,
    ) {
    }

    public function record(GoldenManifest $manifest, CaptureStrategy $strategy): void
    {
        foreach ($manifest->cases() as $case) {
            // Auto-calibration : ce qui diffère entre deux captures à code
            // identique est du bruit → on l'ajoute au masque de la suite. Les deux
            // captures sont espacées pour révéler le bruit temporel (« now »).
            $first = $strategy->capture($case['url']);
            if ($this->calibrationDelayMicros > 0) {
                usleep($this->calibrationDelayMicros);
            }
            $candidates = (new NoiseCalibrator($this->normalizerFor($manifest)))
                ->calibrate($first, $strategy->capture($case['url']));
            if ($candidates !== []) {
                $manifest->addMask($candidates);
            }

            $golden = $this->normalizerFor($manifest)->normalize($strategy->capture($case['url']));
            $this->writeGolden($manifest->name(), $case['id'], $golden);
        }

        $manifest->save();
    }

    /**
     * @return list<GoldenCaseResult>
     */
    public function verify(GoldenManifest $manifest, CaptureStrategy $strategy): array
    {
        $normalizer = $this->normalizerFor($manifest);
        $results = [];

        foreach ($manifest->cases() as $case) {
            $golden = $this->readGolden($manifest->name(), $case['id']);
            if ($golden === null) {
                $results[] = new GoldenCaseResult($case['id'], 'missing', null);
                continue;
            }

            $diff = $this->differ->diff($golden, $normalizer->normalize($strategy->capture($case['url'])));
            $results[] = new GoldenCaseResult($case['id'], $diff->isIdentical() ? 'ok' : 'changed', $diff);
        }

        return $results;
    }

    private function normalizerFor(GoldenManifest $manifest): HtmlNormalizer
    {
        $base = $this->baseConfig ?? NormalizerConfig::default();

        return new HtmlNormalizer(new NormalizerConfig(
            $base->rootSelector(),
            array_merge($base->noiseSelectors(), $manifest->mask()),
        ));
    }

    private function writeGolden(string $suite, string $caseId, string $html): void
    {
        $dir = $this->goldenRootDir . '/' . $suite;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer le dossier golden : {$dir}");
        }
        if (file_put_contents($dir . '/' . $caseId . '.html', $html) === false) {
            throw new \RuntimeException("Écriture du golden échouée : {$dir}/{$caseId}.html");
        }
    }

    private function readGolden(string $suite, string $caseId): ?string
    {
        $file = $this->goldenRootDir . '/' . $suite . '/' . $caseId . '.html';
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }
}
