<?php

declare(strict_types=1);

namespace HtmlCompare\Service;

/**
 * Révision git courante, pour horodater une capture par rapport au code.
 * Best-effort : renvoie null hors dépôt git ou si la commande échoue.
 */
final class GitRevision
{
    public static function current(): ?string
    {
        $lines = [];
        $status = 0;
        @exec('git rev-parse --short HEAD 2>/dev/null', $lines, $status);

        if ($status !== 0 || !isset($lines[0])) {
            return null;
        }

        $revision = trim($lines[0]);

        return $revision === '' ? null : $revision;
    }
}
