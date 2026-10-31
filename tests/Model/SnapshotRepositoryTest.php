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
     * #18 : get() cible un fichier précis — une capture corrompue (JSON tronqué)
     * doit y lever une erreur de domaine nommant le fichier fautif, pas une
     * JsonException « Syntax error » nue.
     */
    public function testGetSurUneCaptureCorrompueLeveUneErreurNommantLeFichier(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/p.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $dir = $this->dir . '/' . $folder;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/1000.0000.json', '{"url":');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('1000.0000.json');
        $repo->get($folder . '_1000.0000');
    }

    /**
     * #18 : un seul fichier corrompu ne doit pas faire planter list()/latest()
     * pour TOUTES les pages. Le fichier fautif est sauté ; les bonnes captures,
     * y compris celles d'autres pages, restent listables.
     */
    public function testListeSauteUnFichierCorrompuEtGardeLesBons(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $bonUrl = 'https://app.local/bon.php';
        $bonFolder = \RenderLoop\Service\UrlFolderNamer::toFolderName($bonUrl);
        $repo->save(new Snapshot($bonUrl, 1000.0, '<p>bon</p>', $bonFolder));

        // Capture corrompue dans une AUTRE page.
        $corrFolder = \RenderLoop\Service\UrlFolderNamer::toFolderName('https://app.local/corr.php');
        $corrDir = $this->dir . '/' . $corrFolder;
        mkdir($corrDir, 0775, true);
        file_put_contents($corrDir . '/2000.0000.json', '{"url":');

        $tous = $repo->list();
        self::assertCount(1, $tous);
        self::assertSame('<p>bon</p>', $tous[0]->html);

        $latest = $repo->latest($bonUrl);
        self::assertNotNull($latest);
        self::assertSame('<p>bon</p>', $latest->html);
    }

    /**
     * #18 : delete() doit pouvoir supprimer une capture corrompue sans la décoder
     * (sinon l'unique voie de récupération serait un `rm` manuel).
     */
    public function testDeleteSupprimeUneCaptureCorrompueSansLaDecoder(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName('https://app.local/corr.php');
        $dir = $this->dir . '/' . $folder;
        mkdir($dir, 0775, true);
        $file = $dir . '/3000.0000.json';
        file_put_contents($file, '{"url":');

        self::assertTrue($repo->delete($folder . '_3000.0000'));
        self::assertFileDoesNotExist($file);
    }

    /**
     * #18 : un `.tmp` orphelin assez ancien (save() interrompu avant le rename)
     * est balayé au prochain save() ; un `.tmp` récent (écriture concurrente)
     * est épargné.
     */
    public function testSaveBalaieLesTemporairesOrphelinsAnciens(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/p.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $dir = $this->dir . '/' . $folder;
        mkdir($dir, 0775, true);

        $vieux = $dir . '/1000.0000.json.ancien.tmp';
        file_put_contents($vieux, 'partiel');
        touch($vieux, time() - 7200);
        $recent = $dir . '/1000.0000.json.recent.tmp';
        file_put_contents($recent, 'partiel');

        $repo->save(new Snapshot($url, 1000.0, '<p>x</p>', $folder));

        self::assertFileDoesNotExist($vieux);
        self::assertFileExists($recent);
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
