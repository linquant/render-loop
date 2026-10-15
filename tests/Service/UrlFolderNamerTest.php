<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Service;

use HtmlCompare\Service\UrlFolderNamer;
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

    public function testSansQueryPasDeSuffixe(): void
    {
        self::assertSame('fiche.php', UrlFolderNamer::toFolderName('https://app.local/fiche.php'));
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
