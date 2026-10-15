<?php

declare(strict_types=1);

namespace RenderLoop;

/**
 * Erreur ATTENDUE, dont le message est sûr à afficher à l'utilisateur
 * (paramètre manquant, capture/référence introuvable…). Les erreurs inattendues
 * (échec d'écriture disque, etc.) restent des exceptions génériques : elles sont
 * loguées et masquées derrière un message générique.
 */
final class HtmlCompareException extends \RuntimeException
{
}
