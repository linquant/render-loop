<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\HtmlDiffer;
use RenderLoop\Service\NormalizerConfig;
use RenderLoop\Service\ScopeAssertionChecker;
use PHPUnit\Framework\TestCase;

class ScopeAssertionCheckerTest extends TestCase
{
    private function checker(): ScopeAssertionChecker
    {
        return new ScopeAssertionChecker(new NormalizerConfig(null, []), new HtmlDiffer(40));
    }

    public function testUnChangementConfineEstSatisfait(): void
    {
        $before = '<div><span class="price">10</span><p>stable</p></div>';
        $after = '<div><span class="price">20</span><p>stable</p></div>';

        $assertion = $this->checker()->check($before, $after, ['.price']);

        self::assertTrue($assertion->satisfied);
    }

    /**
     * Régression #19 : un sélecteur syntaxiquement invalide passé en direct au
     * service doit échouer proprement en nommant le sélecteur fautif (via le
     * masquage), pas remonter une SyntaxErrorException brute du parseur CSS. Le
     * nom de l'option `--expect-selector` est ajouté à la frontière CLI
     * (cf. HtmlCompareCommandTest).
     */
    public function testUnSelecteurInvalideEchoueProprement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid CSS selector "div[["');

        $this->checker()->check('<div>a</div>', '<div>b</div>', ['div[[']);
    }
}
