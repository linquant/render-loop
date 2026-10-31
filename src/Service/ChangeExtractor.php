<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Extrait une liste de {@see Change} localisés entre deux rendus normalisés.
 *
 * Le diff unifié (voir {@see HtmlDiffer}) reste le plancher toujours disponible ;
 * `changes[]` est un enrichissement best-effort : on reparse les deux HTML en DOM
 * et on aligne les éléments porteurs de texte par leur chemin.
 *
 * Deux principes pour que ça tienne sur du HTML legacy bancal :
 *  - le chemin dégrade proprement — `#id` si présent, sinon `tag.classe`, sinon
 *    `tag:nth-of-type(n)` positionnel (moche mais localisant) ;
 *  - chaque changement porte une ANCRE texte (`context`) : le titre/libellé voisin
 *    le plus proche, qui reste lisible même sans aucun id ni classe.
 *
 * Limite assumée : une insertion structurelle décale les chemins positionnels des
 * frères suivants (cascade de faux positifs). Au-delà de {@see self::MAX_CHANGES}
 * entrées on renonce à `changes[]` et on laisse le lecteur retomber sur le diff
 * unifié.
 */
final class ChangeExtractor
{
    private const MAX_CHANGES = 50;

    /** @var list<string> Balises dont le texte sert d'ancre `context`. */
    private const LABEL_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'label', 'th', 'dt', 'legend', 'caption'];

    /** @var list<string> Balises « clé » d'une paire clé/valeur (ligne de tableau, liste de définition). */
    private const PAIR_TAGS = ['td', 'th', 'dt'];

    private const CONTEXT_MAX_LEN = 60;

    /**
     * Compteur d'observabilité : nombre total de nœuds frères visités par
     * {@see self::positionSuffix()} sur le dernier {@see self::extract()}. Scalaire
     * monotone, remis à zéro au début d'`extract()` — il ne dépend d'aucun
     * `spl_object_id` et ne peut donc pas devenir obsolète. Exposé via
     * {@see self::siblingVisits()} pour que la garde anti-régression de #25
     * s'appuie sur un compte d'opérations déterministe plutôt que sur un temps
     * mural (flaky sous CI/xdebug).
     *
     * @var int
     */
    private $siblingVisits = 0;

    /**
     * Nombre de nœuds frères visités lors du dernier {@see self::extract()}.
     * Linéaire en la taille des documents tant que le cache de positions fait son
     * office ; un retour à la complexité quadratique de #25 le ferait exploser.
     */
    public function siblingVisits(): int
    {
        return $this->siblingVisits;
    }

    /**
     * @return list<Change>
     */
    public function extract(string $normalizedBefore, string $normalizedAfter): array
    {
        $this->siblingVisits = 0;

        $before = $this->textRuns($normalizedBefore);
        $after = $this->textRuns($normalizedAfter);

        $changes = [];

        // Parcours dans l'ordre du document « après » : texte modifié ou nœud ajouté.
        foreach ($after as $path => $run) {
            if (!isset($before[$path])) {
                $changes[] = new Change(Change::KIND_ADDED, $run['selector'], $run['context'], null, $run['text']);
            } elseif ($before[$path]['text'] !== $run['text']) {
                $changes[] = new Change(Change::KIND_TEXT, $run['selector'], $run['context'], $before[$path]['text'], $run['text']);
            }
            // Cascade : au-delà du plafond on renonce de toute façon ; on coupe
            // court plutôt que de matérialiser toute la liste pour la jeter ensuite.
            if (count($changes) > self::MAX_CHANGES) {
                return [];
            }
        }

        // Puis les nœuds disparus.
        foreach ($before as $path => $run) {
            if (!isset($after[$path])) {
                $changes[] = new Change(Change::KIND_REMOVED, $run['selector'], $run['context'], $run['text'], null);
            }
            if (count($changes) > self::MAX_CHANGES) {
                return [];
            }
        }

        return $changes;
    }

    /**
     * Un « run » par élément porteur de texte direct, indexé par son chemin.
     *
     * @return array<string, array{text: string, selector: string, context: ?string}>
     */
    private function textRuns(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = $this->load($html);
        $runs = [];

        // Caches de positions `nth-of-type` LOCAUX à cette passe (= à ce document),
        // threadés par référence dans selector()/positionSuffix(). Les garder locaux
        // plutôt qu'en propriétés d'instance rend leur durée de vie structurelle :
        // ils naissent et meurent avec la passe, donc aucun `spl_object_id` d'un
        // document libéré (et recyclé) ne peut subsister, et aucune carte O(n) n'est
        // retenue après coup par une instance réutilisée.
        $nthCache = [];
        $indexedParents = [];

        // Matérialisé en tableau pour garder chaque nœud référencé pendant toute la
        // passe : sans ça PHP libère les wrappers DOM au fil de l'itération et leurs
        // `spl_object_id` sont recyclés, ce qui fausserait le cache de positions.
        $elements = iterator_to_array($dom->getElementsByTagName('*'));

        foreach ($elements as $el) {
            $text = $this->directText($el);
            if ($text === '') {
                continue;
            }
            $selector = $this->selector($el, $nthCache, $indexedParents);
            if ($selector === '') {
                continue;
            }
            $runs[$selector] = [
                'text' => $text,
                'selector' => $selector,
                'context' => $this->context($el),
            ];
        }

        return $runs;
    }

    private function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Texte directement porté par l'élément (ses nœuds texte enfants), espaces
     * normalisés. Le texte des éléments descendants est ignoré : il appartient à
     * leur propre run.
     */
    private function directText(\DOMElement $el): string
    {
        $text = '';
        foreach (iterator_to_array($el->childNodes) as $child) {
            if (!$child instanceof \DOMNode) {
                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue ?? '';
            }
        }

        return $this->collapse($text);
    }

    /**
     * Chemin CSS best-effort. S'arrête (et s'ancre) sur le premier `#id` rencontré
     * en remontant ; sinon `tag.classe` quand il y a des classes, sinon
     * `tag:nth-of-type(n)`. `html`/`body` sont omis pour rester court.
     *
     * @param array<int, int>  $nthCache       cache de positions, threadé par la passe
     * @param array<int, bool> $indexedParents parents déjà indexés, threadé par la passe
     */
    private function selector(\DOMElement $el, array &$nthCache, array &$indexedParents): string
    {
        $parts = [];
        $node = $el;

        while ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'html' || $tag === 'body') {
                break;
            }

            $id = $node->getAttribute('id');
            if ($id !== '') {
                array_unshift($parts, '#' . $id);
                break;
            }

            $class = $this->collapse($node->getAttribute('class'));
            if ($class !== '') {
                $segment = $tag . '.' . implode('.', explode(' ', $class));
            } else {
                $segment = $tag . $this->positionSuffix($node, $nthCache, $indexedParents);
            }

            array_unshift($parts, $segment);
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * `:nth-of-type(n)` émis inconditionnellement pour un élément sans id ni classe.
     * Toujours l'émettre (plutôt que seulement quand il y a plusieurs frères) garde
     * le chemin STABLE : ajouter un frère ne doit pas faire basculer les autres de
     * « sans suffixe » à « avec suffixe » — ce serait une fausse cascade. Le prix
     * est un chemin un peu plus verbeux ; la contrepartie est l'alignement fiable.
     *
     * La position est calculée en UN SEUL balayage par parent, mémorisé dans
     * {@see self::$nthCache} : au premier élément rencontré sous un parent, on
     * numérote tous ses frères d'un coup. Les appels suivants pour les frères (et
     * pour chaque élément du chemin dans {@see self::selector()}) sont alors en
     * O(1). Avant (#25), chaque appel rebalayait tous les frères → O(n²) et plusieurs
     * secondes sur un tableau de milliers de lignes.
     *
     * @param array<int, int>  $nthCache       cache de positions, threadé par la passe
     * @param array<int, bool> $indexedParents parents déjà indexés, threadé par la passe
     */
    private function positionSuffix(\DOMElement $node, array &$nthCache, array &$indexedParents): string
    {
        $parent = $node->parentNode;
        if (!$parent instanceof \DOMNode) {
            return '';
        }

        $parentId = spl_object_id($parent);
        if (!isset($indexedParents[$parentId])) {
            $counts = [];
            foreach ($parent->childNodes as $sibling) {
                // Compte d'opérations déterministe : un seul balayage par parent
                // (grâce au cache) ⇒ total linéaire ; un retour au O(n²) le ferait
                // exploser. C'est sur lui que s'appuie la garde de #25.
                $this->siblingVisits++;
                if (!$sibling instanceof \DOMElement) {
                    continue;
                }
                $tag = $sibling->tagName;
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                $nthCache[spl_object_id($sibling)] = $counts[$tag];
            }
            $indexedParents[$parentId] = true;
        }

        $position = $nthCache[spl_object_id($node)] ?? 1;

        return ':nth-of-type(' . $position . ')';
    }

    /**
     * Ancre texte : le libellé/titre voisin le plus proche. On remonte niveau par
     * niveau et, à chaque niveau, on regarde les frères précédents à la recherche
     * d'un titre (`h1`-`h6`), d'un `label`/`th`/`dt`… ou, pour le motif legacy
     * clé/valeur, d'une cellule `td`/`th`/`dt` précédente.
     */
    private function context(\DOMElement $el): ?string
    {
        $node = $el;

        while ($node instanceof \DOMElement) {
            $sibling = $node->previousSibling;
            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement) {
                    $label = $this->labelText($sibling);
                    if ($label !== null) {
                        return $label;
                    }
                }
                $sibling = $sibling->previousSibling;
            }
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }

        return null;
    }

    private function labelText(\DOMElement $el): ?string
    {
        $tag = strtolower($el->tagName);
        if (!in_array($tag, self::LABEL_TAGS, true) && !in_array($tag, self::PAIR_TAGS, true)) {
            return null;
        }

        $text = $this->collapse($el->textContent);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::CONTEXT_MAX_LEN) {
            $text = mb_substr($text, 0, self::CONTEXT_MAX_LEN - 1) . '…';
        }

        return $text;
    }

    private function collapse(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text);

        return trim($collapsed ?? $text);
    }
}
