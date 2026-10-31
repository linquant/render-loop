<?php

declare(strict_types=1);

namespace RenderLoop\Service;

/**
 * Auto-calibration du bruit : on capture DEUX fois (ou plus) la même page sans
 * rien changer ; tout ce qui diffère entre ces captures identiques EST du bruit
 * (horodatages, compteurs, jetons, ordre non déterministe…). On en déduit des
 * sélecteurs candidats à ajouter au masquage.
 *
 * Détection au niveau du TEXTE direct ET des ATTRIBUTS propres de chaque élément
 * (un `<input value="<timestamp>">` dérive par son attribut, pas par son texte).
 * Synthèse de sélecteur par ordre de robustesse : `#id`, sinon `.classe`, sinon
 * `tag[name="…"]`, sinon un chemin positionnel `…:nth-of-type(n)` (dernier
 * recours, plus fragile — à relire avant de figer dans un masque).
 *
 * Les captures portant sur la MÊME page inchangée, leur structure est identique :
 * un parcours positionnel des éléments est valide (on s'arrête si elle diverge).
 */
final class NoiseCalibrator
{
    private HtmlNormalizer $normalizer;

    public function __construct(HtmlNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * @return list<string> sélecteurs CSS candidats au masquage
     */
    public function calibrate(string $first, string ...$rest): array
    {
        $selectors = [];
        foreach ($rest as $other) {
            foreach ($this->candidatesBetween($first, $other) as $selector) {
                $selectors[] = $selector;
            }
        }

        return array_values(array_unique($selectors));
    }

    /**
     * @return list<string>
     */
    private function candidatesBetween(string $htmlA, string $htmlB): array
    {
        $docA = $this->load($this->normalizer->normalize($htmlA));
        $docB = $this->load($this->normalizer->normalize($htmlB));
        if ($docA === null || $docB === null) {
            return [];
        }

        $elementsA = $this->elements($docA);
        $elementsB = $this->elements($docB);
        $count = min(count($elementsA), count($elementsB));

        $selectors = [];
        for ($i = 0; $i < $count; $i++) {
            $a = $elementsA[$i];
            $b = $elementsB[$i];
            if ($a->tagName !== $b->tagName) {
                break; // structure divergente : le parcours positionnel n'est plus fiable
            }
            if ($this->directText($a) !== $this->directText($b)
                || $this->attributesSignature($a) !== $this->attributesSignature($b)) {
                $selectors[] = $this->selectorFor($a);
            }
        }

        return $selectors;
    }

    private function load(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * @return list<\DOMElement>
     */
    private function elements(\DOMDocument $dom): array
    {
        $nodes = (new \DOMXPath($dom))->query('//*');
        if ($nodes === false) {
            return [];
        }
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Texte directement porté par l'élément (ses nœuds-texte enfants), hors
     * descendants : révèle un compteur ou un horodatage affiché.
     */
    private function directText(\DOMElement $element): string
    {
        $text = '';
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof \DOMNode) {
                continue;
            }
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue ?? '';
            }
        }

        return trim($text);
    }

    /**
     * Signature des attributs PROPRES de l'élément (triés) : détecte une dérive
     * d'attribut (ex. `value` d'un champ date) sans remonter celle des descendants.
     */
    private function attributesSignature(\DOMElement $element): string
    {
        if (!$element->hasAttributes()) {
            return '';
        }
        $pairs = [];
        $attributes = $element->attributes;
        for ($i = 0; $i < $attributes->length; $i++) {
            $attribute = $attributes->item($i);
            if (!$attribute instanceof \DOMAttr) {
                continue;
            }
            $pairs[$attribute->nodeName] = (string) $attribute->nodeValue;
        }
        ksort($pairs);

        $signature = '';
        foreach ($pairs as $name => $value) {
            $signature .= $name . '=' . $value . "\n";
        }

        return $signature;
    }

    /**
     * Synthétise un sélecteur qui désigne CET élément et lui seul. Un candidat de
     * calibration doit matcher exactement UN nœud : sinon, masquer le bruit
     * masquerait aussi d'autres éléments (deux `<span class="badge">`, l'un volatil,
     * l'autre un prix — masquer `.badge` rendrait le golden aveugle au prix). On ne
     * retient donc `.classe` ou `tag[name]` que s'ils sont uniques dans le document ;
     * à défaut on retombe sur un chemin positionnel, lui toujours unique.
     */
    private function selectorFor(\DOMElement $element): string
    {
        $id = $element->getAttribute('id');
        if ($id !== '') {
            return '#' . $id;
        }

        $owner = $element->ownerDocument;

        $class = trim($element->getAttribute('class'));
        if ($class !== '') {
            $first = explode(' ', $class)[0];
            if ($owner === null || $this->countByClass($owner, $first) === 1) {
                return '.' . $first;
            }
        }

        $name = $element->getAttribute('name');
        if ($name !== '') {
            if ($owner === null || $this->countByName($owner, $element->tagName, $name) === 1) {
                return $element->tagName . '[name="' . $name . '"]';
            }
        }

        return $this->positionalPath($element);
    }

    /**
     * Nombre d'éléments du document portant cette classe (n'importe où dans leur
     * liste de classes) : sert à vérifier l'unicité d'un candidat `.classe`.
     */
    private function countByClass(\DOMDocument $document, string $class): int
    {
        $count = 0;
        foreach ($this->elements($document) as $element) {
            $classes = preg_split('/\s+/', trim($element->getAttribute('class')));
            if ($classes !== false && in_array($class, $classes, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Nombre d'éléments de ce tag portant cet attribut `name` : unicité d'un
     * candidat `tag[name="…"]`.
     */
    private function countByName(\DOMDocument $document, string $tag, string $name): int
    {
        $count = 0;
        foreach ($this->elements($document) as $element) {
            if ($element->tagName === $tag && $element->getAttribute('name') === $name) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Chemin CSS positionnel depuis le plus proche ancêtre à `id` (ou la racine).
     * On remonte jusqu'à une ancre `id` ou la racine SANS borne de profondeur : un
     * chemin tronqué (`span:nth-of-type(2)`) matcherait plusieurs nœuds et masquerait
     * trop large. Dernier recours : unique, mais fragile si la structure évolue.
     */
    private function positionalPath(\DOMElement $element): string
    {
        $parts = [];
        $node = $element;

        while ($node instanceof \DOMElement) {
            $id = $node->getAttribute('id');
            if ($id !== '') {
                array_unshift($parts, '#' . $id);

                return implode(' > ', $parts);
            }
            array_unshift($parts, $node->tagName . ':nth-of-type(' . $this->indexOfType($node) . ')');
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    private function indexOfType(\DOMElement $element): int
    {
        $index = 1;
        $sibling = $element->previousSibling;
        while ($sibling !== null) {
            if ($sibling instanceof \DOMElement && $sibling->tagName === $element->tagName) {
                $index++;
            }
            $sibling = $sibling->previousSibling;
        }

        return $index;
    }
}
