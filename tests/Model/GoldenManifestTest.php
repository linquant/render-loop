<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Model;

use RenderLoop\Model\GoldenManifest;
use PHPUnit\Framework\TestCase;

class GoldenManifestTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/golden_manifest_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function write(string $json): void
    {
        file_put_contents($this->path, $json);
    }

    public function testChargeNomCasEtMasque(): void
    {
        $this->write('{"name":"fiche","cases":[{"id":"c1","url":"http://web/f.php?id=1"}],"mask":[".compteur"]}');

        $manifest = GoldenManifest::fromJsonFile($this->path);

        self::assertSame('fiche', $manifest->name());
        self::assertSame([['id' => 'c1', 'url' => 'http://web/f.php?id=1']], $manifest->cases());
        self::assertSame(['.compteur'], $manifest->mask());
    }

    public function testIgnoreLesCasIncomplets(): void
    {
        $this->write('{"cases":[{"id":"ok","url":"http://x"},{"id":"sansurl"},{"url":"http://y"}]}');

        self::assertCount(1, GoldenManifest::fromJsonFile($this->path)->cases());
    }

    public function testAddMaskDedoublonneEtSePersiste(): void
    {
        $this->write('{"name":"s","cases":[],"mask":[".a"]}');
        $manifest = GoldenManifest::fromJsonFile($this->path);

        $manifest->addMask(['.a', '.b']);
        $manifest->save();

        self::assertSame(['.a', '.b'], GoldenManifest::fromJsonFile($this->path)->mask());
    }

    public function testPreserveLesChampsInconnusLorsDeLEnregistrement(): void
    {
        $this->write(
            '{"$schema":"https://render-loop/golden.json","description":"suite boutique",'
            . '"name":"shop","cases":[{"id":"c1","url":"http://web/f.php?id=1"}],"mask":[".compteur"]}'
        );

        $manifest = GoldenManifest::fromJsonFile($this->path);
        $manifest->addMask(['.widget']);
        $manifest->save();

        $written = file_get_contents($this->path);
        self::assertNotFalse($written);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($written, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://render-loop/golden.json', $decoded['$schema']);
        self::assertSame('suite boutique', $decoded['description']);
        self::assertSame('shop', $decoded['name']);
        self::assertSame([['id' => 'c1', 'url' => 'http://web/f.php?id=1']], $decoded['cases']);
        self::assertSame(['.compteur', '.widget'], $decoded['mask']);
    }

    public function testPreserveLesChampsInconnusParCasLorsDeLEnregistrement(): void
    {
        $this->write(
            '{"name":"shop","cases":[{"id":"c1","url":"http://web/f.php?id=1","wait":500,"note":"accueil"}],"mask":[]}'
        );

        $manifest = GoldenManifest::fromJsonFile($this->path);
        $manifest->addMask(['.widget']);
        $manifest->save();

        $written = file_get_contents($this->path);
        self::assertNotFalse($written);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($written, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [['id' => 'c1', 'url' => 'http://web/f.php?id=1', 'wait' => 500, 'note' => 'accueil']],
            $decoded['cases']
        );
    }

    public function testMasqueScalaireMalformeEstIgnore(): void
    {
        $this->write('{"name":"s","cases":[],"mask":".foo"}');

        self::assertSame([], GoldenManifest::fromJsonFile($this->path)->mask());
    }

    public function testNomNonChaineRetombeSurLeNomDuFichier(): void
    {
        $this->write('{"name":123,"cases":[],"mask":[]}');

        $attendu = pathinfo($this->path, PATHINFO_FILENAME);

        self::assertSame($attendu, GoldenManifest::fromJsonFile($this->path)->name());
    }
}
