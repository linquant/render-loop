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
 * Les captures portant sur la MÊME page, leur structure est presque identique. On
 * APPARIE les éléments des deux captures par un chemin d'identité stable (`keyPath` :
 * suite de `:nth-of-type` ancrée sur un `#id`), comme {@see ChangeExtractor::textRuns}
 * le fait déjà — plutôt que de réécrire à la main un alignement de séquences (double
 * curseur), fragile et source de faux positifs. Une insertion/suppression locale
 * (bandeau, barre de debug, bloc promo qui tourne) ne décale pas le `nth-of-type` des
 * éléments d'un AUTRE tag : leur appariement est conservé et le bruit situé après
 * reste détecté. Le bloc inséré/retiré lui-même EST du bruit non déterministe : il est
 * proposé (ADDED/REMOVED). Au-delà de {@see self::MAX_CANDIDATES} candidats — page
 * vraiment restructurée — on renonce (`[]`) plutôt que d'inonder le masque.
 */
final class NoiseCalibrator
{
    private const MAX_CANDIDATES = 50;

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

        // Appariement par chemin d'identité (keyPath), robuste à l'insertion, plutôt
        // qu'un double curseur glouton. Chaque run = la signature (texte direct +
        // attributs propres) de l'élément à ce chemin.
        // keyPath n'ancre que sur un `id` UNIQUE dans le document : en HTML malformé
        // (que ce codebase traite), deux éléments de même `id` — p.ex. une horloge
        // volatile et un prix stable — doivent rester appariés séparément par leur
        // position, sinon le volatil est écrasé par le stable et son bruit est perdu.
        $runsA = $this->runs($docA, $this->idCounts($docA));
        $runsB = $this->runs($docB, $this->idCounts($docB));

        $selectors = [];

        // Ordre du document « B » : signature modifiée (même chemin, contenu dérivé)
        // ou nœud AJOUTÉ (chemin absent de A — un bloc inséré, donc du bruit).
        foreach ($runsB as $path => $run) {
            if (!isset($runsA[$path]) || $runsA[$path]['signature'] !== $run['signature']) {
                $selectors[] = $this->selectorFor($run['element']);
            }
        }

        // Puis les nœuds RETIRÉS (présents dans A, absents de B).
        foreach ($runsA as $path => $run) {
            if (!isset($runsB[$path])) {
                $selectors[] = $this->selectorFor($run['element']);
            }
        }

        // Garde-fou : une page vraiment restructurée produit une avalanche de
        // candidats positionnels peu fiables — on renonce plutôt que d'inonder le
        // masque (même cascade que ChangeExtractor::MAX_CHANGES).
        if (count($selectors) > self::MAX_CANDIDATES) {
            return [];
        }

        return $selectors;
    }

    /**
     * Un run par élément, indexé par son chemin d'identité stable ({@see keyPath}).
     * La signature combine le texte direct et les attributs propres : deux éléments
     * de même chemin sont « identiques » ssi leurs signatures coïncident.
     *
     * @param array<string, int> $idCounts fréquence de chaque `id` dans le document
     *
     * @return array<string, array{signature: string, element: \DOMElement}>
     */
    private function runs(\DOMDocument $document, array $idCounts): array
    {
        $runs = [];
        foreach ($this->elements($document) as $element) {
            $path = $this->keyPath($element, $idCounts);
            if ($path === '') {
                continue; // html/body : pas d'identité propre
            }
            $runs[$path] = [
                'signature' => $this->directText($element) . "\0" . $this->attributesSignature($element),
                'element' => $element,
            ];
        }

        return $runs;
    }

    /**
     * Chemin d'IDENTITÉ stable d'un élément, pour l'APPARIER entre deux captures (pas
     * pour le masquer) : ancré sur le premier `#id` rencontré en remontant, sinon une
     * suite de `tag:nth-of-type(n)` jusqu'à `body`. Insérer/retirer un bloc d'un AUTRE
     * tag ne change pas le `nth-of-type` des éléments suivants, donc leur appariement
     * est conservé. `html`/`body` n'ont pas d'identité propre → chaîne vide (ignorés).
     * On n'ancre sur un `#id` que s'il est UNIQUE dans le document : un `id` dupliqué
     * (HTML malformé) ne distingue pas ses porteurs, alors on retombe sur la position.
     *
     * @param array<string, int> $idCounts fréquence de chaque `id` dans le document
     */
    private function keyPath(\DOMElement $element, array $idCounts): string
    {
        $parts = [];
        $node = $element;

        while ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'html' || $tag === 'body') {
                break;
            }
            $id = $node->getAttribute('id');
            if ($id !== '' && ($idCounts[$id] ?? 0) === 1) {
                array_unshift($parts, '#' . $id);
                break;
            }
            array_unshift($parts, $tag . ':nth-of-type(' . $this->indexOfType($node) . ')');
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * Fréquence de chaque `id` NON VIDE du document, en un seul parcours — sert à
     * n'ancrer un keyPath sur un `#id` que lorsqu'il est unique.
     *
     * @return array<string, int>
     */
    private function idCounts(\DOMDocument $document): array
    {
        $counts = [];
        foreach ($this->elements($document) as $element) {
            $id = $element->getAttribute('id');
            if ($id !== '') {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
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
