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
    public function __construct(private readonly HtmlNormalizer $normalizer)
    {
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
        foreach ($element->childNodes as $child) {
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
        foreach ($element->attributes as $attribute) {
            $pairs[$attribute->nodeName] = (string) $attribute->nodeValue;
        }
        ksort($pairs);

        $signature = '';
        foreach ($pairs as $name => $value) {
            $signature .= $name . '=' . $value . "\n";
        }

        return $signature;
    }

    private function selectorFor(\DOMElement $element): string
    {
        $id = $element->getAttribute('id');
        if ($id !== '') {
            return '#' . $id;
        }

        $class = trim($element->getAttribute('class'));
        if ($class !== '') {
            return '.' . explode(' ', $class)[0];
        }

        $name = $element->getAttribute('name');
        if ($name !== '') {
            return $element->tagName . '[name="' . $name . '"]';
        }

        return $this->positionalPath($element);
    }

    /**
     * Chemin CSS positionnel depuis le plus proche ancêtre à `id` (ou la racine),
     * borné en profondeur. Dernier recours : fragile si la structure évolue.
     */
    private function positionalPath(\DOMElement $element): string
    {
        $parts = [];
        $node = $element;
        $depth = 0;

        while ($node instanceof \DOMElement && $depth < 5) {
            $id = $node->getAttribute('id');
            if ($id !== '') {
                array_unshift($parts, '#' . $id);

                return implode(' > ', $parts);
            }
            array_unshift($parts, $node->tagName . ':nth-of-type(' . $this->indexOfType($node) . ')');
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
            $depth++;
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
