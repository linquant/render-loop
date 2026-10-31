<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\Change;
use RenderLoop\Service\ChangeExtractor;
use PHPUnit\Framework\TestCase;

class ChangeExtractorTest extends TestCase
{
    public function testDeuxRendusIdentiquesNeProduisentAucunChangement(): void
    {
        $html = '<div class="product"><span class="price">1295.00</span></div>';

        self::assertSame([], (new ChangeExtractor())->extract($html, $html));
    }

    public function testUnChangementDeTexteEstLocaliseParSelecteurAvecAvantApres(): void
    {
        $before = '<div class="product"><span class="price">1295.00</span></div>';
        $after = '<div class="product"><span class="price">995.00</span></div>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        $change = $changes[0];
        self::assertSame(Change::KIND_TEXT, $change->kind);
        self::assertSame('div.product > span.price', $change->selector);
        self::assertSame('1295.00', $change->before);
        self::assertSame('995.00', $change->after);
    }

    public function testLeTitreVoisinSertDAncreContexte(): void
    {
        $before = '<div><h2>Aeron Chair</h2><span class="price">1295.00</span></div>';
        $after = '<div><h2>Aeron Chair</h2><span class="price">995.00</span></div>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame('Aeron Chair', $changes[0]->context);
    }

    /**
     * Motif legacy clé/valeur sans aucun id ni classe : la cellule précédente sert
     * d'ancre. C'est ce qui rend le signal lisible sur du vieux HTML de tableau.
     */
    public function testCellulePrecedenteSertDAncreSurUnTableauSansClasse(): void
    {
        $before = '<table><tr><td>Prix</td><td>1295.00</td></tr></table>';
        $after = '<table><tr><td>Prix</td><td>995.00</td></tr></table>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_TEXT, $changes[0]->kind);
        self::assertSame('Prix', $changes[0]->context);
        self::assertSame('1295.00', $changes[0]->before);
        self::assertSame('995.00', $changes[0]->after);
    }

    public function testUnNoeudAjouteEstDetecteSansFaireCascaderSesFreres(): void
    {
        $before = '<ul><li>a</li></ul>';
        $after = '<ul><li>a</li><li>b</li></ul>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_ADDED, $changes[0]->kind);
        self::assertNull($changes[0]->before);
        self::assertSame('b', $changes[0]->after);
    }

    public function testUnNoeudSupprimeEstDetecte(): void
    {
        $before = '<ul><li>a</li><li>b</li></ul>';
        $after = '<ul><li>a</li></ul>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_REMOVED, $changes[0]->kind);
        self::assertSame('b', $changes[0]->before);
        self::assertNull($changes[0]->after);
    }

    /**
     * #20 : deux frères partageant une classe produisent le même `selector`
     * (`ul:nth-of-type(1) > li.item`). Indexer les runs par cette chaîne faisait
     * écraser le premier par le second, et `changes[]` ratait la modification du
     * premier alors que le diff unifié, lui, la montrait.
     */
    public function testUnChangementSurUnFrereQuiPartageUneClasseEstDetecte(): void
    {
        $before = '<ul><li class="item">A</li><li class="item">B</li></ul>';
        $after = '<ul><li class="item">X</li><li class="item">B</li></ul>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_TEXT, $changes[0]->kind);
        self::assertSame('A', $changes[0]->before);
        self::assertSame('X', $changes[0]->after);
    }

    /**
     * #20 : même défaut avec un id dupliqué (HTML invalide mais fréquent en legacy) —
     * le `selector` s'ancre sur `#x` pour les deux, donc seule la clé positionnelle
     * les distingue.
     */
    public function testUnChangementSurUnFrereAIdDupliqueEstDetecte(): void
    {
        $before = '<ul><li id="x">A</li><li id="x">B</li></ul>';
        $after = '<ul><li id="x">X</li><li id="x">B</li></ul>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_TEXT, $changes[0]->kind);
        self::assertSame('A', $changes[0]->before);
        self::assertSame('X', $changes[0]->after);
    }

    /**
     * #32 : insérer un frère de MÊME balise avant un conteneur porteur d'un id
     * unique décale le `nth-of-type` positionnel du conteneur. Avec une clé
     * purement positionnelle, ce décalage cascade sur tous les descendants à texte
     * (faux couples REMOVED+ADDED). En ancrant la clé sur l'id unique, le
     * sous-arbre reste indépendant de la position de ses ancêtres : seule la vraie
     * insertion apparaît.
     */
    public function testUneInsertionAvantUnConteneurAIdUniqueNeCascadePasSurSesDescendants(): void
    {
        $before = '<body><div id="panel"><p>a</p><p>b</p><p>c</p></div></body>';
        $after = '<body><div class="new"><p>z</p></div><div id="panel"><p>a</p><p>b</p><p>c</p></div></body>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(Change::KIND_ADDED, $changes[0]->kind);
        self::assertNull($changes[0]->before);
        self::assertSame('z', $changes[0]->after);
    }

    /**
     * #32 (corollaire) : une modification de texte à l'intérieur du conteneur à id
     * unique reste correctement localisée malgré l'insertion d'un frère de même
     * balise en amont — la clé ancrée sur l'id apparie l'avant et l'après.
     */
    public function testUnChangementDansUnConteneurAIdUniqueResteLocaliseMalgreUneInsertionEnAmont(): void
    {
        $before = '<body><div id="panel"><p>a</p><p>b</p></div></body>';
        $after = '<body><div class="new"><p>z</p></div><div id="panel"><p>a</p><p>B</p></div></body>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        $texts = array_values(array_filter(
            $changes,
            static function (Change $c): bool {
                return $c->kind === Change::KIND_TEXT;
            }
        ));
        self::assertCount(1, $texts);
        self::assertSame('b', $texts[0]->before);
        self::assertSame('B', $texts[0]->after);
    }

    /**
     * Au-delà du plafond, on considère que les chemins ont cascadé (insertion
     * structurelle) et on renonce à `changes[]` : le lecteur retombe sur le diff
     * unifié, le plancher toujours disponible.
     */
    public function testUneCascadeMassiveRenonceAuxChanges(): void
    {
        $before = '<body>';
        $after = '<body>';
        for ($i = 0; $i < 60; $i++) {
            $before .= "<p>old $i</p>";
            $after .= "<p>new $i</p>";
        }
        $before .= '</body>';
        $after .= '</body>';

        self::assertSame([], (new ChangeExtractor())->extract($before, $after));
    }

    /**
     * #25 : le calcul des positions `nth-of-type` est désormais mémorisé en un
     * passage par parent. On vérifie qu'il reste EXACT avec beaucoup de frères —
     * l'élément modifié doit toujours porter la bonne position dans son sélecteur.
     */
    public function testLaPositionNthOfTypeResteExacteAvecDeNombreuxFreres(): void
    {
        $before = '<div>';
        $after = '<div>';
        for ($i = 1; $i <= 6; $i++) {
            $cell = $i === 4 ? '<p>old</p>' : "<p>p$i</p>";
            $before .= $cell;
            $after .= ($i === 4 ? '<p>new</p>' : "<p>p$i</p>");
        }
        $before .= '</div>';
        $after .= '</div>';

        $changes = (new ChangeExtractor())->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame('div:nth-of-type(1) > p:nth-of-type(4)', $changes[0]->selector);
        self::assertSame('old', $changes[0]->before);
        self::assertSame('new', $changes[0]->after);
    }

    /**
     * #25 : garde anti-régression de performance ciblée sur {@see
     * ChangeExtractor::positionSuffix()}.
     *
     * On empile des milliers de frères `dt`/`dd` sous un seul parent : c'est le cas
     * où `positionSuffix()` rebalayait TOUS les frères à chaque élément, soit un coût
     * quadratique (plusieurs secondes, voire bien plus, avant le correctif). La
     * structure `dl` est choisie à dessein pour que l'ancre `context` reste en O(1)
     * (le `dt` frère précédent est trouvé immédiatement), de sorte que ce test isole
     * le passage corrigé.
     *
     * On asserte sur un COMPTE D'OPÉRATIONS déterministe (le nombre de frères
     * visités, exposé par {@see ChangeExtractor::siblingVisits()}) plutôt que sur un
     * temps mural : une borne en secondes dépend de la charge CI / de xdebug et peut
     * flaker. Avec le cache, chaque parent n'est balayé qu'une fois, donc le total
     * est linéaire (~2 documents × nombre d'enfants) ; un retour à la complexité
     * quadratique de #25 le ferait bondir en O(n²), loin au-dessus de la borne.
     */
    public function testUnGrosNombreDeFreresResteSousUneBorneLineaireDOperations(): void
    {
        $pairs = 4000;
        $before = '<dl>';
        $after = '<dl>';
        for ($i = 0; $i < $pairs; $i++) {
            $before .= "<dt>k$i</dt><dd>" . ($i === 0 ? 'old' : "v$i") . '</dd>';
            $after .= "<dt>k$i</dt><dd>" . ($i === 0 ? 'new' : "v$i") . '</dd>';
        }
        $before .= '</dl>';
        $after .= '</dl>';

        $extractor = new ChangeExtractor();
        $changes = $extractor->extract($before, $after);

        self::assertCount(1, $changes);
        self::assertSame('old', $changes[0]->before);
        self::assertSame('new', $changes[0]->after);

        // 2 balises × $pairs enfants, sur 2 documents.
        $elements = 2 * 2 * $pairs;
        // Linéaire : une poignée de visites par enfant (ici ~1). La borne large
        // absorbe les petites variations de structure ; un O(n²) ferait ~($pairs²).
        self::assertLessThan(
            5 * $elements,
            $extractor->siblingVisits(),
            sprintf(
                'Trop de frères visités (%d pour %d éléments) : la complexité quadratique de positionSuffix() est probablement revenue.',
                $extractor->siblingVisits(),
                $elements
            )
        );
    }
}
