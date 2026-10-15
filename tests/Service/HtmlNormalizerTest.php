<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NormalizerConfig;
use PHPUnit\Framework\TestCase;

class HtmlNormalizerTest extends TestCase
{
    /**
     * @param list<string> $noise
     */
    private function normalizer(?string $root = null, array $noise = []): HtmlNormalizer
    {
        return new HtmlNormalizer(new NormalizerConfig($root, $noise));
    }

    public function testSupprimeUnNoeudDeBruitParSelecteur(): void
    {
        $html = '<div><p class="garder">texte</p><div class="phpdebugbar">debug</div></div>';

        $out = $this->normalizer(null, ['.phpdebugbar'])->normalize($html);

        self::assertStringNotContainsString('debug', $out);
        self::assertStringContainsString('texte', $out);
    }

    public function testSupprimeUnJetonCsrfParAttribut(): void
    {
        $html = '<form><input name="csrf_token" value="SECRET"><input name="email"></form>';

        $out = $this->normalizer(null, ['input[name=csrf_token]'])->normalize($html);

        self::assertStringNotContainsString('SECRET', $out);
        self::assertStringContainsString('email', $out);
    }

    /**
     * Le cœur du contrat : on masque par sélecteur, jamais le contenu chiffré.
     * Un prix tombé à 0,00 reste visible — c'est exactement la régression à voir.
     */
    public function testUnPrixTombeAZeroResteVisible(): void
    {
        $out = $this->normalizer()->normalize('<span class="prix">0,00</span>');

        self::assertStringContainsString('0,00', $out);
    }

    public function testRestreintAuRootSelector(): void
    {
        $html = '<body><nav>menu</nav><main id="contenu"><p>utile</p></main></body>';

        $out = $this->normalizer('#contenu')->normalize($html);

        self::assertStringContainsString('utile', $out);
        self::assertStringNotContainsString('menu', $out);
    }

    public function testEstIdempotent(): void
    {
        $normalizer = $this->normalizer(null, ['.phpdebugbar']);
        $html = '<div><p>un</p><ul><li>a</li><li>b</li></ul><div class="phpdebugbar">x</div></div>';

        $une = $normalizer->normalize($html);
        $deux = $normalizer->normalize($une);

        self::assertSame($une, $deux);
    }

    public function testLeContenuDesScriptsEtStylesEstMasque(): void
    {
        $html = '<div><p>visible</p><script>var secret = 1;</script><style>.x{color:red}</style></div>';

        $out = $this->normalizer(null, ['script', 'style'])->normalize($html);

        self::assertStringContainsString('visible', $out);
        self::assertStringNotContainsString('secret', $out);
        self::assertStringNotContainsString('color:red', $out);
    }

    public function testLaConfigParDefautRetireScriptsStylesEtCommentaires(): void
    {
        $html = '<div><p>visible</p><script>var secret=1;</script>'
            . '<style>.x{color:red}</style><!-- commentaire volatil --></div>';

        $out = (new HtmlNormalizer(NormalizerConfig::default()))->normalize($html);

        self::assertStringContainsString('visible', $out);
        self::assertStringNotContainsString('secret', $out);
        self::assertStringNotContainsString('color:red', $out);
        self::assertStringNotContainsString('commentaire', $out);
    }

    /**
     * Cas réel : un <script> hors d'un arbre DOM bien formé (HTML malformé) doit
     * quand même disparaître — c'est le rôle de la pré-passe regex.
     */
    public function testUnScriptDansUnHtmlMalformeEstRetire(): void
    {
        $html = '<p>ok</p><body><script type="text/javascript">phpdebugbar.addDataSet({});</script>';

        $out = (new HtmlNormalizer(NormalizerConfig::default()))->normalize($html);

        self::assertStringNotContainsString('phpdebugbar', $out);
        self::assertStringNotContainsString('<script', $out);
    }

    public function testHtmlVideDonneChaineVide(): void
    {
        self::assertSame('', $this->normalizer()->normalize('   '));
    }
}
