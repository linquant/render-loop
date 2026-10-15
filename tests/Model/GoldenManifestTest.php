<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Model;

use HtmlCompare\Model\GoldenManifest;
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
}
