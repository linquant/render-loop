<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Un changement localisé entre deux rendus : *où* (un sélecteur CSS best-effort),
 * *à côté de quoi* (le libellé/titre voisin le plus proche — l'ancre qui reste
 * lisible même sur du HTML legacy sans id ni classe) et *quoi* (ancien/nouveau
 * contenu). Pensé pour qu'une IA décide sur le `summary` JSON sans dérouler le
 * diff unifié.
 */
final class Change
{
    public const KIND_TEXT = 'text';
    public const KIND_ADDED = 'added';
    public const KIND_REMOVED = 'removed';

    public function __construct(
        public readonly string $kind,
        public readonly string $selector,
        public readonly ?string $context,
        public readonly ?string $before,
        public readonly ?string $after,
    ) {
    }

    /**
     * @return array{kind: string, selector: string, context: ?string, before: ?string, after: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'selector' => $this->selector,
            'context' => $this->context,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
