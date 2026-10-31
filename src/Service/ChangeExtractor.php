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
     * @return list<Change>
     */
    public function extract(string $normalizedBefore, string $normalizedAfter): array
    {
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
        }

        // Puis les nœuds disparus.
        foreach ($before as $path => $run) {
            if (!isset($after[$path])) {
                $changes[] = new Change(Change::KIND_REMOVED, $run['selector'], $run['context'], $run['text'], null);
            }
        }

        // Cascade : trop de changements pour un signal fiable → on renonce.
        if (count($changes) > self::MAX_CHANGES) {
            return [];
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

        foreach ($dom->getElementsByTagName('*') as $el) {
            $text = $this->directText($el);
            if ($text === '') {
                continue;
            }
            $selector = $this->selector($el);
            if ($selector === '') {
                continue;
            }
            // Indexé par un chemin positionnel UNIQUE (voir {@see self::keyPath()}),
            // jamais par le `selector` lisible : deux frères qui partagent une classe
            // — ou un id dupliqué — produisent le même `selector`, et l'indexer par
            // cette chaîne ferait écraser les précédents par le dernier (#20).
            $runs[$this->keyPath($el)] = [
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
     */
    private function selector(\DOMElement $el): string
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
                $segment = $tag . $this->positionSuffix($node);
            }

            array_unshift($parts, $segment);
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * Chemin positionnel UNIQUE par élément, servant de CLÉ d'indexation des runs
     * (≠ du `selector` lisible produit par {@see self::selector()}).
     *
     * Contrairement au `selector`, il n'intègre ni `#id` ni `.classe` : il empile un
     * `tag:nth-of-type(n)` à chaque niveau. Deux frères qui partagent une classe (ou
     * un id dupliqué) ont le même `selector` mais des positions distinctes, donc des
     * clés distinctes — sans quoi le dernier écraserait les précédents et `changes[]`
     * raterait la modification (#20).
     */
    private function keyPath(\DOMElement $el): string
    {
        $parts = [];
        $node = $el;

        while ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'html' || $tag === 'body') {
                break;
            }

            array_unshift($parts, $tag . $this->positionSuffix($node));
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
     */
    private function positionSuffix(\DOMElement $node): string
    {
        $parent = $node->parentNode;
        if (!$parent instanceof \DOMNode) {
            return '';
        }

        $tag = $node->tagName;
        $position = 0;
        foreach ($parent->childNodes as $sibling) {
            if ($sibling instanceof \DOMElement && $sibling->tagName === $tag) {
                $position++;
                if ($sibling === $node) {
                    return ':nth-of-type(' . $position . ')';
                }
            }
        }

        return ':nth-of-type(1)';
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
