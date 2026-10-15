<?php

declare(strict_types=1);

namespace HtmlCompare\Service;

/**
 * Résultat de vérification d'un cas golden.
 * `status` : `ok` (identique), `changed` (a dérivé), `missing` (golden absent).
 */
final class GoldenCaseResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?DiffResult $diff,
    ) {
    }
}
