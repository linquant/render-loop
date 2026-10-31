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
     * Régression #19 : un `--expect-selector` syntaxiquement invalide doit échouer
     * proprement en nommant le sélecteur ET l'option, pas remonter une
     * SyntaxErrorException brute depuis le parseur CSS.
     */
    public function testUnExpectSelectorInvalideEchoueProprement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--expect-selector');

        $this->checker()->check('<div>a</div>', '<div>b</div>', ['div[[']);
    }
}
