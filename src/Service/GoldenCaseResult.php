<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Résultat de vérification d'un cas golden.
 * `status` : `ok` (identique), `changed` (a dérivé), `missing` (golden absent).
 */
final class GoldenCaseResult
{
    public string $id;

    public string $status;

    public ?DiffResult $diff;

    public function __construct(
        string $id,
        string $status,
        ?DiffResult $diff
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->diff = $diff;
    }
}
