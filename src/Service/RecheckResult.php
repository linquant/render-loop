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
    public function __construct(
        public readonly Snapshot $baseline,
        public readonly Snapshot $current,
        public readonly DiffResult $diff,
    ) {
    }
}
