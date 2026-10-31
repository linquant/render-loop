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

    /**
     * #24 (régression) : la protection anti-traversée ne doit pas sur-bloquer un
     * id légitime. `UrlFolderNamer` conserve les points, donc un dossier peut
     * contenir `..` (ex. `foo..php`) ou commencer par un point (ex. `.env.php`)
     * sans aucune remontée de répertoire. save() les écrit : get() et delete()
     * doivent pouvoir les relire et les effacer.
     */
    public function testIdsLegitimesAvecPointsRestentAccessibles(): void
    {
        $repo = new SnapshotRepository($this->dir);

        foreach (['foo..php', '.env.php'] as $folder) {
            $snapshot = new Snapshot('https://app.local/x', 1000.0, '<p>ok</p>', $folder);
            $id = $repo->save($snapshot);

            $relu = $repo->get($id);
            self::assertNotNull($relu, "get() doit relire l'id légitime {$id}");
            self::assertSame('<p>ok</p>', $relu->html);
            self::assertTrue($repo->delete($id), "delete() doit effacer l'id légitime {$id}");
            self::assertNull($repo->get($id));
        }
    }

    /**
     * Régression #16 : deux `.php` de même basename dans des répertoires
     * différents (`admin/index.php` vs `shop/index.php`) ne doivent pas partager
     * la même baseline. `latest()` d'une URL ne doit jamais renvoyer la capture
     * de l'autre page.
     */
    public function testBaselinesNeSeContaminentPasEntreMemesBasenames(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $admin = 'http://localhost/admin/index.php';
        $shop = 'http://localhost/shop/index.php';

        $repo->save(Snapshot::create($admin, '<p>ADMIN</p>', 'admin'));
        $repo->save(Snapshot::create($shop, '<p>SHOP</p>', 'shop'));

        $latestAdmin = $repo->latest($admin);
        $latestShop = $repo->latest($shop);

        self::assertNotNull($latestAdmin);
        self::assertNotNull($latestShop);
        self::assertSame('<p>ADMIN</p>', $latestAdmin->html);
        self::assertSame('<p>SHOP</p>', $latestShop->html);
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
