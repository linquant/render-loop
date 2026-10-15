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
}
