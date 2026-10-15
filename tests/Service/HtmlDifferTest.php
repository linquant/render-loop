<?php

declare(strict_types=1);

namespace HtmlCompare\Tests\Service;

use HtmlCompare\Service\HtmlDiffer;
use PHPUnit\Framework\TestCase;

class HtmlDifferTest extends TestCase
{
    public function testDeuxHtmlIdentiquesNeProduisentAucunDiff(): void
    {
        $html = "<div>\n<p>a</p>\n</div>";

        $result = (new HtmlDiffer())->diff($html, $html);

        self::assertTrue($result->isIdentical());
        self::assertSame(0, $result->linesAdded);
        self::assertSame(0, $result->linesRemoved);
        self::assertSame('', $result->unifiedDiff);
    }

    public function testUneLigneModifieeEstVisibleDansLeDiff(): void
    {
        $before = "<div>\n<span>19,90</span>\n</div>";
        $after = "<div>\n<span>0,00</span>\n</div>";

        $result = (new HtmlDiffer())->diff($before, $after);

        self::assertFalse($result->isIdentical());
        self::assertGreaterThan(0, $result->linesAdded);
        self::assertGreaterThan(0, $result->linesRemoved);
        self::assertStringContainsString('0,00', $result->unifiedDiff);
    }

    /**
     * Un changement de TEXTE seul ne remonte aucune balise : le <span> n'a pas
     * changé, seul son contenu. (Avant ce correctif, la ligne entière — donc
     * toutes ses balises — était remontée à tort.)
     */
    public function testUnChangementDeTexteSeulNeRemontePasDeBalise(): void
    {
        $before = "<p>a</p><span>Liste des tutos</span><input><br><div></div>";
        $after = "<p>a</p><span>Liste des tutoriels</span><input><br><div></div>";

        $result = (new HtmlDiffer())->diff($before, $after);

        self::assertSame([], $result->tagsTouched);
    }

    public function testUnChangementStructurelRemonteLaBalise(): void
    {
        $before = "<div>\n<p>a</p>\n</div>";
        $after = "<div>\n<p>a</p>\n<span>b</span>\n</div>";

        $result = (new HtmlDiffer())->diff($before, $after);

        self::assertContains('span', $result->tagsTouched);
    }

    public function testLeNombreDeHunksEstPlafonne(): void
    {
        // Deux changements espacés par assez de contexte → deux hunks distincts.
        $lines = [];
        for ($i = 0; $i < 40; $i++) {
            $lines[] = "<p>L{$i}</p>";
        }
        $before = implode("\n", $lines);

        $modified = $lines;
        $modified[2] = '<p>CHANGE-HAUT</p>';
        $modified[35] = '<p>CHANGE-BAS</p>';
        $after = implode("\n", $modified);

        $result = (new HtmlDiffer(1))->diff($before, $after);

        self::assertGreaterThanOrEqual(2, $result->hunks);
        self::assertTrue($result->truncated);
    }

    public function testResumeExposeLesCompteurs(): void
    {
        $result = (new HtmlDiffer())->diff("<p>a</p>", "<p>b</p>");

        $summary = $result->summary();

        self::assertArrayHasKey('lines_added', $summary);
        self::assertArrayHasKey('lines_removed', $summary);
        self::assertArrayHasKey('hunks', $summary);
        self::assertArrayHasKey('truncated', $summary);
        self::assertArrayHasKey('tags_touched', $summary);
    }
}
