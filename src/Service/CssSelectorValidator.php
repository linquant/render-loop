<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface as CssSelectorException;

/**
 * Convertit un sélecteur CSS en XPath en traduisant les exceptions du parseur
 * CSS (SyntaxErrorException, ExpressionErrorException…) en une \RuntimeException
 * ACTIONNABLE : elle nomme le sélecteur fautif ET l'option d'où il vient, au lieu
 * de laisser échapper une trace brute (le `catch` du CLI ne gère que
 * InvalidArgumentException|RuntimeException).
 *
 * Un `#price:main` (id HTML légal avec deux-points) ou un `div[[` (typo) doit
 * donc échouer proprement, pas planter — et être rejeté AVANT d'être écrit dans
 * la config.
 */
final class CssSelectorValidator
{
    public static function toXPath(CssSelectorConverter $converter, string $selector, string $context): string
    {
        try {
            return $converter->toXPath($selector);
        } catch (CssSelectorException $e) {
            throw new \RuntimeException(
                sprintf('Invalid CSS selector "%s" (%s): %s', $selector, $context, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Valide un sélecteur sans en garder le XPath — sert à le refuser avant de
     * l'écrire dans la config (calibrate --apply, mask-add).
     */
    public static function assertValid(string $selector, string $context): void
    {
        self::toXPath(new CssSelectorConverter(), $selector, $context);
    }
}
