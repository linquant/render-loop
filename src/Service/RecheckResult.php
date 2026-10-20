<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use RenderLoop\Model\Snapshot;

/**
 * Résultat d'un recontrôle : la capture de référence, la capture courante, et le
 * diff entre les deux une fois normalisées.
 */
final class RecheckResult
{
    public Snapshot $baseline;

    public Snapshot $current;

    public DiffResult $diff;

    public function __construct(
        Snapshot $baseline,
        Snapshot $current,
        DiffResult $diff
    ) {
        $this->baseline = $baseline;
        $this->current = $current;
        $this->diff = $diff;
    }
}
