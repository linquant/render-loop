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

        // Les candidats sont synthétisés sur les éléments de leur propre document :
        // on construit la table de fréquence de chaque capture une seule fois (un
        // parcours) au lieu de rescanner le document pour chaque élément bruité. Le
        // sélecteur d'un nœud de B (modifié ou ajouté) doit être jugé unique DANS B,
        // celui d'un nœud retiré, unique dans A — sinon un bloc inséré propre à B
        // (ex. `.bandeau`) serait rejeté par la fréquence de A où il n'existe pas.
        $frequenciesA = $this->frequencies($docA);
        $frequenciesB = $this->frequencies($docB);

        // Appariement par chemin d'identité (keyPath), robuste à l'insertion, plutôt
        // qu'un double curseur glouton. Chaque run = la signature (texte direct +
        // attributs propres) de l'élément à ce chemin. On passe la table de fréquence
        // des `id` : keyPath n'ancre que sur un `id` UNIQUE (HTML malformé, que ce
        // codebase traite : deux éléments de même `id` doivent rester appariés
        // séparément par leur position, sinon le volatil est écrasé par le stable).
        $runsA = $this->runs($docA, $frequenciesA['id']);
        $runsB = $this->runs($docB, $frequenciesB['id']);

        $selectors = [];

        // Ordre du document « B » : signature modifiée (même chemin, contenu dérivé)
        // ou nœud AJOUTÉ (chemin absent de A — un bloc inséré, donc du bruit).
        // Appariement par keyPath (#26) ; le sélecteur émis garde les garde-fous
        // d'unicité de #17 via la table de fréquences de B.
        foreach ($runsB as $path => $run) {
            if (!isset($runsA[$path]) || $runsA[$path]['signature'] !== $run['signature']) {
                $selectors[] = $this->selectorFor($run['element'], $frequenciesB);
            }
        }

        // Puis les nœuds RETIRÉS (présents dans A, absents de B).
        foreach ($runsA as $path => $run) {
            if (!isset($runsB[$path])) {
                $selectors[] = $this->selectorFor($run['element'], $frequenciesA);
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
     * @param array<string,int> $idCounts fréquence de chaque `id` dans le document
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
     * On n'ancre que sur un `id` UNIQUE : un `id` dupliqué (HTML malformé) n'identifie
     * rien et ferait collisionner deux éléments distincts sur le même chemin.
     *
     * @param array<string,int> $idCounts fréquence de chaque `id` dans le document
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
     * l'autre un prix — masquer `.badge` rendrait le golden aveugle au prix ; de même
     * une horloge et un prix partageant `id="val"` sur du HTML malformé, que ce
     * codebase traite). On ne retient donc `#id`, `.classe` ou `tag[name]` que s'ils
     * sont uniques dans le document ; à défaut on retombe sur un chemin positionnel,
     * lui toujours unique.
     *
     * @param array{id: array<string,int>, class: array<string,int>, name: array<string,int>} $frequencies
     */
    private function selectorFor(\DOMElement $element, array $frequencies): string
    {
        $id = $element->getAttribute('id');
        if ($id !== '' && ($frequencies['id'][$id] ?? 0) === 1) {
            return '#' . $id;
        }

        $class = trim($element->getAttribute('class'));
        if ($class !== '') {
            $first = $this->firstClass($class);
            if ($first !== '' && ($frequencies['class'][$first] ?? 0) === 1) {
                return '.' . $first;
            }
        }

        $name = $element->getAttribute('name');
        if ($name !== '') {
            $key = $element->tagName . "\0" . $name;
            if (($frequencies['name'][$key] ?? 0) === 1) {
                return $element->tagName . '[name="' . $name . '"]';
            }
        }

        return $this->positionalPath($element, $frequencies);
    }

    /**
     * Première classe de la liste, découpée sur tout blanc HTML (espace, tabulation,
     * retour ligne) : le MÊME découpage que la table de fréquence, sinon un jeton
     * contenant une tabulation ne se retrouverait jamais dans la table et une classe
     * pourtant unique serait rejetée.
     */
    private function firstClass(string $class): string
    {
        $tokens = preg_split('/\s+/', trim($class));
        if ($tokens === false || !isset($tokens[0])) {
            return '';
        }

        return $tokens[0];
    }

    /**
     * Table de fréquence du document, construite en UN parcours : combien d'éléments
     * portent chaque `id`, chaque classe, chaque couple `tag`+`name`. Consultée pour
     * vérifier qu'un candidat ne matche qu'un seul nœud, sans rescanner le document
     * à chaque élément bruité.
     *
     * @return array{id: array<string,int>, class: array<string,int>, name: array<string,int>}
     */
    private function frequencies(\DOMDocument $document): array
    {
        $frequencies = ['id' => [], 'class' => [], 'name' => []];
        foreach ($this->elements($document) as $element) {
            $id = $element->getAttribute('id');
            if ($id !== '') {
                $frequencies['id'][$id] = ($frequencies['id'][$id] ?? 0) + 1;
            }

            $class = trim($element->getAttribute('class'));
            if ($class !== '') {
                $tokens = preg_split('/\s+/', $class);
                if ($tokens !== false) {
                    // Dédoublonné par élément : `.badge` compte l'élément une fois,
                    // même si sa liste de classes répète le jeton.
                    foreach (array_unique($tokens) as $token) {
                        if ($token === '') {
                            continue;
                        }
                        $frequencies['class'][$token] = ($frequencies['class'][$token] ?? 0) + 1;
                    }
                }
            }

            $name = $element->getAttribute('name');
            if ($name !== '') {
                $key = $element->tagName . "\0" . $name;
                $frequencies['name'][$key] = ($frequencies['name'][$key] ?? 0) + 1;
            }
        }

        return $frequencies;
    }

    /**
     * Chemin CSS positionnel depuis le plus proche ancêtre à `id` UNIQUE (ou la
     * racine). On remonte jusqu'à une ancre `id` unique ou la racine SANS borne de
     * profondeur : un chemin tronqué (`span:nth-of-type(2)`) matcherait plusieurs
     * nœuds et masquerait trop large. Un `id` partagé n'est pas une ancre fiable (il
     * désignerait plusieurs sous-arbres) : on le traverse comme un nœud ordinaire.
     * Dernier recours : unique, mais fragile si la structure évolue.
     *
     * @param array{id: array<string,int>, class: array<string,int>, name: array<string,int>} $frequencies
     */
    private function positionalPath(\DOMElement $element, array $frequencies): string
    {
        $parts = [];
        $node = $element;

        while ($node instanceof \DOMElement) {
            $id = $node->getAttribute('id');
            if ($id !== '' && ($frequencies['id'][$id] ?? 0) === 1) {
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
