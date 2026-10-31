<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Régression SSRF (#23) : l'exemple web ne doit JAMAIS dériver la liste blanche
 * des hôtes de l'en-tête Host fourni par le client ($_SERVER['HTTP_HOST']). Le
 * cookie de session étant attaché à tout hôte autorisé, faire confiance à
 * HTTP_HOST autoriserait une capture (cookie compris) vers un domaine attaquant.
 *
 * Le helper est extrait dans `examples/web/allowlist.php` : on l'inclut seul,
 * sans exécuter le shell de l'exemple (routeur JSON + view.phtml), donc sans
 * effet de bord ni risque de redéclaration.
 */
class WebExampleAllowlistTest extends TestCase
{
    /** @var mixed */
    private $savedHttpHost;

    private bool $avaitHttpHost = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../examples/web/allowlist.php';
    }

    protected function setUp(): void
    {
        // On sauvegarde $_SERVER['HTTP_HOST'] pour le restaurer : une mutation
        // qui fuite polluerait tout test ultérieur lu dans le même process.
        $this->avaitHttpHost = array_key_exists('HTTP_HOST', $_SERVER);
        $this->savedHttpHost = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->avaitHttpHost) {
            $_SERVER['HTTP_HOST'] = $this->savedHttpHost;
        } else {
            unset($_SERVER['HTTP_HOST']);
        }
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

    /**
     * #23 : la valeur configurée ÉTEND la boucle locale, elle ne la remplace pas.
     * Sinon `RENDERLOOP_ALLOWED_HOSTS=app.local` casserait silencieusement la
     * capture de `http://localhost:8000/` (la façon documentée de lancer l'exemple).
     */
    public function testLaListeConfigureeEtendLaBoucleLocale(): void
    {
        self::assertSame(
            ['localhost', '127.0.0.1', 'app.local', 'web'],
            \renderloop_allowed_hosts('app.local, web ,'),
        );
    }

    public function testLaBoucleLocaleNestJamaisDupliquee(): void
    {
        self::assertSame(
            ['localhost', '127.0.0.1', 'app.local'],
            \renderloop_allowed_hosts('localhost, app.local, 127.0.0.1'),
        );
    }
}
