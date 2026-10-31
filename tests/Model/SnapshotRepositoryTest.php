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
     * #24 : get() découpe l'id au dernier « _ » pour composer un chemin. Un id
     * tel que « ../secret_<clé> » remonte d'un cran et lit un .json valide hors
     * du dossier racine. La validation doit le refuser (null), même si la cible
     * existe réellement.
     */
    public function testGetRefuseLaLectureHorsDuDossierRacine(): void
    {
        $repo = new SnapshotRepository($this->dir . '/snapshots');
        mkdir($this->dir . '/snapshots', 0775, true);

        // Une capture valide, mais DÉPOSÉE hors du dossier racine du dépôt.
        $evil = new Snapshot('https://evil.local/x', 1000.0, '<p>secret</p>', '../secret');
        $outside = $this->dir . '/secret';
        mkdir($outside, 0775, true);
        $cible = $outside . '/' . $evil->key() . '.json';
        file_put_contents($cible, (string) json_encode($evil->toArray()));

        // rootDir/../secret/<clé>.json pointe exactement sur $cible : sans
        // validation, get() le lirait et renverrait la capture.
        self::assertNull($repo->get('../secret_' . $evil->key()));
        self::assertNull($repo->get('/etc/passwd_x'));
        self::assertNull($repo->get('.hidden_1'));
    }

    /**
     * #24 : delete() délègue à get(). Sans validation, un id qui remonte hors du
     * dossier racine ferait unlink() sur la cible extérieure ; la validation de
     * get() doit l'en empêcher (false, et le fichier survit).
     */
    public function testDeleteRefuseLEffacementHorsDuDossierRacine(): void
    {
        $repo = new SnapshotRepository($this->dir . '/snapshots');
        mkdir($this->dir . '/snapshots', 0775, true);

        // La capture déposée porte un champ folder « ../secret » et une clé qui
        // correspond au nom du fichier : sans validation, delete() recomposerait
        // rootDir/../secret/<clé>.json et effacerait réellement la cible.
        $evil = new Snapshot('https://evil.local/x', 1000.0, '<p>secret</p>', '../secret');
        $outside = $this->dir . '/secret';
        mkdir($outside, 0775, true);
        $cible = $outside . '/' . $evil->key() . '.json';
        file_put_contents($cible, (string) json_encode($evil->toArray()));

        self::assertFalse($repo->delete('../secret_' . $evil->key()));
        self::assertFileExists($cible);
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
