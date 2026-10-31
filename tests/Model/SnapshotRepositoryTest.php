<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Model;

use RenderLoop\Model\Snapshot;
use RenderLoop\Model\SnapshotRepository;
use PHPUnit\Framework\TestCase;

class SnapshotRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htmlcompare_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testSauveEtRelitAvecSesMetadonnees(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $snapshot = Snapshot::create('https://app.local/fiche.php?id=1', '<p>x</p>', 'avant', 'abc1234');

        $id = $repo->save($snapshot);
        $relu = $repo->get($id);

        self::assertNotNull($relu);
        self::assertSame('https://app.local/fiche.php?id=1', $relu->url);
        self::assertSame('<p>x</p>', $relu->html);
        self::assertSame('avant', $relu->tag);
        self::assertSame('abc1234', $relu->gitCommit);
    }

    public function testGetSurIdInconnuRenvoieNull(): void
    {
        $repo = new SnapshotRepository($this->dir);

        self::assertNull($repo->get('inexistant_123'));
    }

    public function testLatestRenvoieLaCaptureLaPlusRecente(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/p.php';

        $repo->save(new Snapshot($url, 1000, '<p>vieux</p>', \RenderLoop\Service\UrlFolderNamer::toFolderName($url)));
        $repo->save(new Snapshot($url, 2000, '<p>recent</p>', \RenderLoop\Service\UrlFolderNamer::toFolderName($url)));

        $latest = $repo->latest($url);

        self::assertNotNull($latest);
        self::assertSame('<p>recent</p>', $latest->html);
    }

    /**
     * Deux captures dans la même seconde (prix 19,90 puis recontrôle immédiat)
     * ne doivent pas partager le même fichier et s'écraser : la clé est à la
     * microseconde.
     */
    public function testDeuxCapturesDansLaMemeSecondeNeSEcrasentPas(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);

        $idAvant = $repo->save(new Snapshot($url, 1791648528.1234, '<p>19,90</p>', $folder, 'avant'));
        $idApres = $repo->save(new Snapshot($url, 1791648528.1235, '<p>24,90</p>', $folder, 'apres'));

        self::assertNotSame($idAvant, $idApres);
        self::assertCount(2, $repo->list($folder));
        $avant = $repo->get($idAvant);
        $apres = $repo->get($idApres);
        self::assertNotNull($avant);
        self::assertNotNull($apres);
        self::assertSame('<p>19,90</p>', $avant->html);
        self::assertSame('<p>24,90</p>', $apres->html);
    }

    /**
     * #18 : une capture corrompue (JSON tronqué) doit lever une erreur de domaine
     * nommant le fichier fautif, pas une JsonException « Syntax error » nue.
     */
    public function testUneCaptureCorrompueLeveUneErreurNommantLeFichier(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/p.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $dir = $this->dir . '/' . $folder;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/1000.0000.json', '{"url":');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('1000.0000.json');
        $repo->list($folder);
    }

    /**
     * #18 : l'écriture est atomique (.tmp + rename) ; aucun fichier temporaire
     * ne doit subsister, et seul le .json final reste.
     */
    public function testLEcritureEstAtomiqueEtNeLaissePasDeTemporaire(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/p.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);

        $id = $repo->save(Snapshot::create($url, '<p>x</p>'));

        self::assertNotNull($repo->get($id));
        self::assertSame([], glob($this->dir . '/' . $folder . '/*.tmp') ?: []);
        self::assertCount(1, glob($this->dir . '/' . $folder . '/*.json') ?: []);
    }

    public function testListeFiltreParPage(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $repo->save(Snapshot::create('https://app.local/a.php', '<p>a</p>'));
        $repo->save(Snapshot::create('https://app.local/b.php', '<p>b</p>'));

        $folderA = \RenderLoop\Service\UrlFolderNamer::toFolderName('https://app.local/a.php');

        self::assertCount(1, $repo->list($folderA));
        self::assertCount(2, $repo->list());
    }
}
