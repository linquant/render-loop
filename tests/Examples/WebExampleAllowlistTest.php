<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Régression SSRF (#23) : l'exemple web ne doit JAMAIS dériver la liste blanche
 * des hôtes de l'en-tête Host fourni par le client ($_SERVER['HTTP_HOST']). Le
 * cookie de session étant attaché à tout hôte autorisé, faire confiance à
 * HTTP_HOST autoriserait une capture (cookie compris) vers un domaine attaquant.
 */
class WebExampleAllowlistTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // L'exemple d'intégration utilise la syntaxe PHP 8 (opérateur nullsafe) :
        // l'inclure sous PHP 7.4 serait une erreur d'analyse. Les symboles
        // restent connus de PHPStan via `scanFiles`.
        if (PHP_VERSION_ID < 80000) {
            self::markTestSkipped('L\'exemple web requiert PHP 8.0+ (opérateur nullsafe).');
        }

        // Charge les fonctions de l'exemple sans déclencher le routeur JSON
        // (qui ferait exit) ni laisser fuir le HTML du shell.
        unset($_GET['hc']);
        ob_start();
        require_once __DIR__ . '/../../examples/web/index.php';
        ob_end_clean();
    }

    public function testParDefautSeuleLaBoucleLocaleEstAutorisee(): void
    {
        self::assertSame(['localhost', '127.0.0.1'], \renderloop_allowed_hosts(null));
        self::assertSame(['localhost', '127.0.0.1'], \renderloop_allowed_hosts(''));
        self::assertSame(['localhost', '127.0.0.1'], \renderloop_allowed_hosts('   '));
    }

    public function testLHoteHttpHostNestJamaisInjecteDansLaListe(): void
    {
        // Même avec un Host attaquant posé par le client, la liste par défaut
        // ne doit pas le contenir : la fonction ne lit pas les superglobales.
        $_SERVER['HTTP_HOST'] = 'attacker.example';

        $hosts = \renderloop_allowed_hosts(null);

        self::assertNotContains('attacker.example', $hosts);
    }

    public function testLaListeConfigureeEstLueEtNettoyee(): void
    {
        self::assertSame(
            ['app.local', 'web'],
            \renderloop_allowed_hosts('app.local, web ,'),
        );
    }
}
