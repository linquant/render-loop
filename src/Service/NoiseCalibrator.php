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
 * Les captures portant sur la MÊME page, leur structure est presque identique : un
 * parcours positionnel aligné est valide. Une insertion/suppression locale (bandeau,
 * barre de debug) décale les listes sans invalider le reste : on resynchronise et on
 * poursuit le parcours plutôt que d'abandonner à la première divergence.
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
        $countA = count($elementsA);
        $countB = count($elementsB);

        // Parcours aligné par deux curseurs. Un élément inséré/retiré (une barre de
        // debug, un bandeau, un bloc promo qui tourne) décale les deux listes : on
        // ne s'arrête PLUS à la première divergence (sinon tout le bruit situé après
        // — horodatages, compteurs — passe inaperçu). On resynchronise en sautant
        // l'index désaligné du côté qui a l'élément en trop, puis on continue.
        $selectors = [];
        $i = 0;
        $j = 0;
        while ($i < $countA && $j < $countB) {
            $a = $elementsA[$i];
            $b = $elementsB[$j];

            if ($a->tagName !== $b->tagName) {
                if ($j + 1 < $countB && $a->tagName === $elementsB[$j + 1]->tagName) {
                    $j++; // élément inséré dans B : on le saute
                } elseif ($i + 1 < $countA && $elementsA[$i + 1]->tagName === $b->tagName) {
                    $i++; // élément inséré dans A : on le saute
                } else {
                    $i++; // divergence non résoluble : on avance des deux côtés
                    $j++;
                }
                continue;
            }

            if ($this->directText($a) !== $this->directText($b)
                || $this->attributesSignature($a) !== $this->attributesSignature($b)) {
                $selectors[] = $this->selectorFor($a);
            }
            $i++;
            $j++;
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
