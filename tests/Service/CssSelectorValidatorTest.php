<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\CssSelectorValidator;
use Symfony\Component\CssSelector\CssSelectorConverter;
use PHPUnit\Framework\TestCase;

class CssSelectorValidatorTest extends TestCase
{
    public function testUnSelecteurValideEstConverti(): void
    {
        $xpath = CssSelectorValidator::toXPath(new CssSelectorConverter(), '.box', 'test');

        self::assertStringContainsString('box', $xpath);
    }

    /**
     * Régression #19 : une typo (`div[[`) doit échouer proprement en nommant le
     * sélecteur ET l'option, pas remonter une SyntaxErrorException brute.
     */
    public function testUnSelecteurSyntaxiquementInvalideEchoueProprement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('div[[');

        CssSelectorValidator::assertValid('div[[', '--expect-selector');
    }

    /**
     * Régression #19 : un id HTML légal avec deux-points (`#price:main`) est lu
     * comme la pseudo-classe `:main` (ExpressionErrorException) ; il doit échouer
     * proprement, pas planter.
     */
    public function testUnPseudoNonSupporteEchoueProprement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('#price:main');

        CssSelectorValidator::assertValid('#price:main', '--selector');
    }

    public function testLeMessageNommeLOption(): void
    {
        try {
            CssSelectorValidator::assertValid('div[[', 'calibrate --apply');
            self::fail('Une exception était attendue.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('calibrate --apply', $e->getMessage());
        }
    }

    /**
     * Régression #19 : `isValid` sert à trier les candidats GÉNÉRÉS par
     * calibrate — un candidat invalide (p. ex. `#price:main`) est écarté pour que
     * les candidats valides restent appliqués, au lieu d'avorter tout l'apply.
     */
    public function testIsValidTrieLesCandidats(): void
    {
        self::assertTrue(CssSelectorValidator::isValid('.compteur'));
        self::assertFalse(CssSelectorValidator::isValid('#price:main'));
        self::assertFalse(CssSelectorValidator::isValid('div[['));
    }
}
