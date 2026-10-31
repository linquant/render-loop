<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Normalise un HTML avant comparaison : retire les nœuds de bruit (DebugBar,
 * jetons CSRF, chrome du site…), restreint éventuellement à un sous-arbre, puis
 * ré-sérialise avec une mise en forme stable (un nœud par ligne) pour que le
 * diff ligne-à-ligne qui suit soit lisible.
 *
 * Le masquage se fait par SÉLECTEUR, jamais par regex sur le contenu : un prix
 * qui tombe à 0,00 doit rester visible dans le diff, pas être effacé comme du bruit.
 *
 * Exception : scripts, styles et commentaires sont retirés par une pré-passe
 * regex AVANT le DOM. Sur du HTML réel malformé, le parseur DOM produit un arbre
 * bancal et laisse échapper des `<script>` ; la pré-passe est robuste quel que
 * soit l'état du HTML. Elle retire des blocs non-visibles entiers, jamais une
 * valeur métier — la règle « pas de regex sur le contenu » reste respectée.
 */
final class HtmlNormalizer
{
    private NormalizerConfig $config;

    private CssSelectorConverter $converter;

    public function __construct(NormalizerConfig $config)
    {
        $this->config = $config;
        $this->converter = new CssSelectorConverter();
    }

    public function normalize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = $this->stripNonVisible($html);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $previous = libxml_use_internal_errors(true);
        // Le préfixe force l'UTF-8 ; le PI résultant est retiré ensuite.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $child = $dom->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            if ($child instanceof \DOMProcessingInstruction) {
                $dom->removeChild($child);
            }
            $child = $next;
        }

        $xpath = new \DOMXPath($dom);

        foreach ($this->config->noiseSelectors() as $selector) {
            $this->removeMatching($xpath, $selector);
        }

        $root = $this->config->rootSelector();
        if ($root !== null && $root !== '') {
            // Validation du sélecteur (#19) : une erreur de parse CSS devient un
            // message actionnable nommant d'où vient le sélecteur fautif.
            $scoped = $xpath->query(CssSelectorValidator::toXPath($this->converter, $root, 'root_selector in the normalizer config'));
            if ($scoped !== false && $scoped->length > 0) {
                // Sérialiser TOUS les nœuds correspondants, pas seulement le
                // premier : sinon un changement dans un second `.box` resterait
                // invisible (les deux rendus se normaliseraient à l'identique).
                // Voir issue #21.
                //
                // Mais ne garder que les correspondances les PLUS EXTERNES : si
                // un nœud correspondant est imbriqué dans un autre nœud
                // correspondant (même sélecteur, p. ex. `.box` dans `.box`),
                // l'ancêtre le sérialise déjà. Sérialiser aussi le descendant
                // dupliquerait son contenu, gonflerait le diff et re-compterait
                // le même texte en aval (issue #20). Pas de copie via
                // iterator_to_array : la boucle ne mute pas le DOM.
                $matched = [];
                foreach ($scoped as $node) {
                    if ($node instanceof \DOMNode) {
                        $matched[] = $node;
                    }
                }

                $parts = [];
                foreach ($matched as $node) {
                    if ($this->isDescendantOfAny($node, $matched)) {
                        continue;
                    }
                    $serialized = $dom->saveHTML($node);
                    if ($serialized !== false) {
                        $parts[] = trim($serialized);
                    }
                }

                return implode("\n", $parts);
            }
        }

        $output = $dom->saveHTML();

        return $output === false ? '' : trim($output);
    }

    /**
     * Retire scripts, styles et commentaires avant le parsing DOM — blocs
     * non-visibles, souvent volumineux et volatils (DebugBar, nonces, horodatages).
     */
    private function stripNonVisible(string $html): string
    {
        $clean = preg_replace(
            [
                '#<script\b[^>]*>.*?</script>#is',
                '#<style\b[^>]*>.*?</style>#is',
                '#<!--.*?-->#s',
            ],
            '',
            $html,
        );

        return $clean ?? $html;
    }

    /**
     * Le nœud est-il un descendant (strict) d'un AUTRE nœud de la liste ?
     *
     * @param list<\DOMNode> $others
     */
    private function isDescendantOfAny(\DOMNode $node, array $others): bool
    {
        $parent = $node->parentNode;
        while ($parent !== null) {
            foreach ($others as $other) {
                if ($other === $parent) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function removeMatching(\DOMXPath $xpath, string $selector): void
    {
        $nodes = $xpath->query(CssSelectorValidator::toXPath($this->converter, $selector, 'noise_selectors in the normalizer config'));
        if ($nodes === false) {
            return;
        }

        // Matérialiser avant suppression : retirer un nœud mute la NodeList vivante.
        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof \DOMNode) {
                continue;
            }
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}
