<?php

declare(strict_types=1);

namespace HtmlCompare\Service;

/**
 * Deux manières d'obtenir le HTML d'une page : aller le chercher (curl, pour les
 * pages rendues serveur) ou le recevoir déjà capturé (push, pour les pages JS
 * rendues par un navigateur headless externe).
 */
interface CaptureStrategy
{
    public function capture(string $url): string;
}
