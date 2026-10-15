<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\NoiseConfigWriter;
use RenderLoop\Service\NormalizerConfig;
use PHPUnit\Framework\TestCase;

class NoiseConfigWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/noisecfg_' . uniqid() . '.json';
        file_put_contents($this->path, json_encode([
            'root_selector' => null,
            'noise_selectors' => ['.phpdebugbar'],
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAjouteLesNouveauxSelecteursEtLesRenvoie(): void
    {
        $added = (new NoiseConfigWriter())->append($this->path, ['.server-time', '.request-id']);

        self::assertSame(['.server-time', '.request-id'], $added);

        $config = NormalizerConfig::fromJsonFile($this->path);
        self::assertSame(['.phpdebugbar', '.server-time', '.request-id'], $config->noiseSelectors());
    }

    public function testNeDupliquePasEtNeRenvoieQueLesAjouts(): void
    {
        $added = (new NoiseConfigWriter())->append($this->path, ['.phpdebugbar', '.new-noise']);

        self::assertSame(['.new-noise'], $added);
        self::assertSame(
            ['.phpdebugbar', '.new-noise'],
            NormalizerConfig::fromJsonFile($this->path)->noiseSelectors(),
        );
    }

    public function testIgnoreLesEntreesVidesEtNEcritPasSiRienAAjouter(): void
    {
        $before = (string) file_get_contents($this->path);

        $added = (new NoiseConfigWriter())->append($this->path, ['', '  ', '.phpdebugbar']);

        self::assertSame([], $added);
        // Rien à ajouter → le fichier n'est pas réécrit.
        self::assertSame($before, (string) file_get_contents($this->path));
    }

    public function testFichierIntrouvableLeveUneException(): void
    {
        $this->expectException(\RuntimeException::class);

        (new NoiseConfigWriter())->append($this->path . '.absent', ['.x']);
    }
}
