<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\UrlFolderNamer;
use PHPUnit\Framework\TestCase;

class UrlFolderNamerTest extends TestCase
{
    /**
     * Le bug historique : le legacy réduisait `fiche.php?id=1` à `fiche.php` et
     * écrasait `?id=2` dans le même dossier. Les deux doivent diverger.
     */
    public function testDeuxQueryStringsDonnentDesDossiersDifferents(): void
    {
        $un = UrlFolderNamer::toFolderName('https://app.local/fiche.php?id=1');
        $deux = UrlFolderNamer::toFolderName('https://app.local/fiche.php?id=2');

        self::assertNotSame($un, $deux);
    }

    /**
     * L'ordre des paramètres ne change pas la page : les deux doivent converger.
     */
    public function testLOrdreDesParametresEstNormalise(): void
    {
        $ab = UrlFolderNamer::toFolderName('https://app.local/p.php?a=1&b=2');
        $ba = UrlFolderNamer::toFolderName('https://app.local/p.php?b=2&a=1');

        self::assertSame($ab, $ba);
    }

    public function testEstDeterministe(): void
    {
        $url = 'https://app.local/commande.php?ref=XYZ&page=3';

        self::assertSame(
            UrlFolderNamer::toFolderName($url),
            UrlFolderNamer::toFolderName($url),
        );
    }

    public function testUnScriptPhpUtiliseSonBasename(): void
    {
        self::assertStringStartsWith('fiche.php', UrlFolderNamer::toFolderName('https://app.local/dir/fiche.php'));
    }

    public function testSansQueryPasDeSuffixeDeQuery(): void
    {
        $sansQuery = UrlFolderNamer::toFolderName('https://app.local/fiche.php');
        $avecQuery = UrlFolderNamer::toFolderName('https://app.local/fiche.php?id=1');

        // Le basename reste lisible, seul le hash host+path est accolé...
        self::assertStringStartsWith('fiche.php_', $sansQuery);
        // ...et la query ajoute bien un suffixe supplémentaire.
        self::assertStringStartsWith($sansQuery . '_', $avecQuery);
    }

    /**
     * Régression #16 : deux `.php` de même basename dans des répertoires
     * différents ne doivent pas atterrir dans le même dossier (sinon leurs
     * baselines se contaminent).
     */
    public function testMemeBasenameDansDesRepertoiresDifferentsNeCollisionnentPas(): void
    {
        $admin = UrlFolderNamer::toFolderName('http://localhost/admin/index.php');
        $shop = UrlFolderNamer::toFolderName('http://localhost/shop/index.php');

        self::assertStringStartsWith('index.php', $admin);
        self::assertStringStartsWith('index.php', $shop);
        self::assertNotSame($admin, $shop);
    }

    /**
     * Régression #16 : le même basename sur deux hôtes différents ne doit pas
     * collisionner non plus.
     */
    public function testMemeBasenameSurDesHotesDifferentsNeCollisionnentPas(): void
    {
        $prod = UrlFolderNamer::toFolderName('http://localhost/a.php');
        $staging = UrlFolderNamer::toFolderName('http://staging.local/a.php');

        self::assertNotSame($prod, $staging);
    }

    /**
     * Régression #16 : le même host et le même path sur deux ports différents
     * désignent deux pages différentes et ne doivent pas partager de dossier.
     */
    public function testDeuxPortsNeCollisionnentPas(): void
    {
        $p8080 = UrlFolderNamer::toFolderName('http://localhost:8080/a.php');
        $p8081 = UrlFolderNamer::toFolderName('http://localhost:8081/a.php');

        self::assertStringStartsWith('a.php', $p8080);
        self::assertStringStartsWith('a.php', $p8081);
        self::assertNotSame($p8080, $p8081);
    }

    /**
     * Régression #16 : `http://` et `https://` sur le même host/path sont deux
     * pages distinctes ; le schéma doit entrer dans le hash d'unicité.
     */
    public function testHttpEtHttpsNeCollisionnentPas(): void
    {
        $http = UrlFolderNamer::toFolderName('http://app.local/a.php');
        $https = UrlFolderNamer::toFolderName('https://app.local/a.php');

        self::assertNotSame($http, $https);
    }

    public function testNomSurSeulementDesCaracteresDeDossier(): void
    {
        $name = UrlFolderNamer::toFolderName('https://app.local/chemin/avec espace/é?q=a b&c=/x');

        self::assertSame(1, preg_match('/^[a-z0-9_\-.]+$/i', $name), "Nom non sûr : {$name}");
    }

    public function testUrlVideOuInvalideNeCassePas(): void
    {
        self::assertNotSame('', UrlFolderNamer::toFolderName(''));
        self::assertNotSame('', UrlFolderNamer::toFolderName('http:///'));
    }
}
