<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Service;

use HtmlCompare\Model\GoldenManifest;
use HtmlCompare\Service\CaptureStrategy;
use HtmlCompare\Service\GoldenMaster;
use HtmlCompare\Service\HtmlDiffer;
use PHPUnit\Framework\TestCase;

class GoldenMasterTest extends TestCase
{
    private string $dir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/golden_' . uniqid();
        mkdir($this->dir, 0775, true);
        $this->manifestPath = $this->dir . '/fiche.json';
        file_put_contents($this->manifestPath, json_encode([
            'name' => 'fiche',
            'cases' => [
                ['id' => 'c1', 'url' => 'http://web/f.php?id=1'],
                ['id' => 'c2', 'url' => 'http://web/f.php?id=2'],
            ],
            'mask' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @param array<string, string> $map
     */
    private function strategy(array $map): CaptureStrategy
    {
        return new class($map) implements CaptureStrategy {
            /** @param array<string, string> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function capture(string $url): string
            {
                return $this->map[$url] ?? '';
            }
        };
    }

    private function master(): GoldenMaster
    {
        return new GoldenMaster(new HtmlDiffer(), $this->dir);
    }

    public function testRecordPuisVerifyIdentiqueEstVert(): void
    {
        $strategy = $this->strategy([
            'http://web/f.php?id=1' => '<main><p>19,90</p></main>',
            'http://web/f.php?id=2' => '<main><p>5,00</p></main>',
        ]);

        $this->master()->record(GoldenManifest::fromJsonFile($this->manifestPath), $strategy);
        $results = $this->master()->verify(GoldenManifest::fromJsonFile($this->manifestPath), $strategy);

        self::assertCount(2, $results);
        foreach ($results as $r) {
            self::assertSame('ok', $r->status, "cas {$r->id}");
        }
    }

    public function testVerifyDetecteUneRegressionSurUnSeulCas(): void
    {
        $base = [
            'http://web/f.php?id=1' => '<main><p>19,90</p></main>',
            'http://web/f.php?id=2' => '<main><p>5,00</p></main>',
        ];
        $this->master()->record(GoldenManifest::fromJsonFile($this->manifestPath), $this->strategy($base));

        // Le calcul du cas 1 a dérivé après « refactoring ».
        $apres = $base;
        $apres['http://web/f.php?id=1'] = '<main><p>18,50</p></main>';
        $results = $this->master()->verify(GoldenManifest::fromJsonFile($this->manifestPath), $this->strategy($apres));

        $byId = [];
        foreach ($results as $r) {
            $byId[$r->id] = $r->status;
        }
        self::assertSame('changed', $byId['c1']);
        self::assertSame('ok', $byId['c2']);
    }

    public function testGoldenAbsentEstSignale(): void
    {
        // On vérifie sans avoir enregistré.
        $results = $this->master()->verify(
            GoldenManifest::fromJsonFile($this->manifestPath),
            $this->strategy(['http://web/f.php?id=1' => '<p>x</p>', 'http://web/f.php?id=2' => '<p>y</p>']),
        );

        self::assertSame('missing', $results[0]->status);
    }
}
