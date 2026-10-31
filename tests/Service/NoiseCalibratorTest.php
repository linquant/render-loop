<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NoiseCalibrator;
use RenderLoop\Service\NormalizerConfig;
use PHPUnit\Framework\TestCase;

class NoiseCalibratorTest extends TestCase
{
    private function calibrator(): NoiseCalibrator
    {
        return new NoiseCalibrator(new HtmlNormalizer(NormalizerConfig::default()));
    }

    public function testDeuxCapturesIdentiquesNeProposentRien(): void
    {
        $html = '<main id="contenu"><p>Bonjour</p></main>';

        self::assertSame([], $this->calibrator()->calibrate($html, $html));
    }

    public function testUnCompteurVolatilEstProposeParSaClasse(): void
    {
        $a = '<main id="contenu"><p class="compteur">Visiteurs : 416</p></main>';
        $b = '<main id="contenu"><p class="compteur">Visiteurs : 714</p></main>';

        self::assertSame(['.compteur'], $this->calibrator()->calibrate($a, $b));
    }

    public function testUnElementVolatilAvecIdEstProposeParSonId(): void
    {
        $a = '<div><span id="horloge">10:00:01</span></div>';
        $b = '<div><span id="horloge">10:00:02</span></div>';

        self::assertSame(['#horloge'], $this->calibrator()->calibrate($a, $b));
    }

    public function testBruitDansUnAttributEstDetecte(): void
    {
        // Le champ date dérive par sa VALUE (attribut), pas par son texte.
        $a = '<form><input id="date_debut" value="1790404782"></form>';
        $b = '<form><input id="date_debut" value="1790404843"></form>';

        self::assertSame(['#date_debut'], $this->calibrator()->calibrate($a, $b));
    }

    public function testUnElementVolatilAvecNameEstProposeParSonName(): void
    {
        $a = '<form><input name="debut_date" value="100"></form>';
        $b = '<form><input name="debut_date" value="200"></form>';

        self::assertSame(['input[name="debut_date"]'], $this->calibrator()->calibrate($a, $b));
    }

    public function testUnElementSansAncreEstProposeParUnCheminPositionnel(): void
    {
        // Pas d'id/classe/name : dernier recours, un chemin positionnel.
        $a = '<main id="contenu"><p>prix 19,90</p></main>';
        $b = '<main id="contenu"><p>prix 24,90</p></main>';

        self::assertSame(['#contenu > p:nth-of-type(1)'], $this->calibrator()->calibrate($a, $b));
    }

    public function testUneClassePartageeNEstPasProposeeEtNeMasquePasUnVraiChangement(): void
    {
        // Deux <span class="badge"> : une horloge volatile, un prix stable. Masquer
        // `.badge` rendrait le golden aveugle au prix — le candidat doit être unique.
        $a = '<html><body><span class="badge">10:00:01</span><span class="badge">1295.00</span></body></html>';
        $b = '<html><body><span class="badge">10:00:02</span><span class="badge">1295.00</span></body></html>';

        $selecteurs = $this->calibrator()->calibrate($a, $b);

        self::assertNotContains('.badge', $selecteurs, 'un sélecteur de classe partagée masquerait trop large');
        self::assertCount(1, $selecteurs);

        // Appliqué comme masque, le candidat laisse voir un vrai changement de prix.
        $masque = new HtmlNormalizer(new NormalizerConfig(null, $selecteurs));
        $avant = $masque->normalize($a);
        $apres = $masque->normalize(str_replace('1295.00', '0.00', $b));

        self::assertNotSame($avant, $apres, 'un changement réel de prix ne doit pas être masqué par la calibration');
    }

    public function testUnIdPartageNEstPasProposeEtNeMasquePasUnVraiChangement(): void
    {
        // HTML malformé (que ce codebase traite) : une horloge volatile et un prix
        // stable partagent id="val". Masquer `#val` rendrait le golden aveugle au
        // prix — le candidat doit rester unique, donc retomber sur un chemin
        // positionnel plutôt que sur l'id dupliqué.
        $a = '<html><body><span id="val">10:00:01</span><span id="val">1295.00</span></body></html>';
        $b = '<html><body><span id="val">10:00:02</span><span id="val">1295.00</span></body></html>';

        $selecteurs = $this->calibrator()->calibrate($a, $b);

        self::assertNotContains('#val', $selecteurs, 'un sélecteur d\'id partagé masquerait trop large');
        self::assertCount(1, $selecteurs);

        // Appliqué comme masque, le candidat laisse voir un vrai changement de prix.
        $masque = new HtmlNormalizer(new NormalizerConfig(null, $selecteurs));
        $avant = $masque->normalize($a);
        $apres = $masque->normalize(str_replace('1295.00', '0.00', $b));

        self::assertNotSame($avant, $apres, 'un changement réel de prix ne doit pas être masqué par un id dupliqué');
    }

    public function testUneClasseSepareeParTabulationEstReconnueUnique(): void
    {
        // Séparateur blanc HTML autre que l'espace : la première classe doit être
        // extraite avec le MÊME découpage que la table de fréquence, sinon une
        // classe unique est rejetée et on retombe sur un chemin positionnel fragile.
        $a = "<main id=\"c\"><p class=\"ts\tvolatile\">Visiteurs : 416</p></main>";
        $b = "<main id=\"c\"><p class=\"ts\tvolatile\">Visiteurs : 714</p></main>";

        self::assertSame(['.ts'], $this->calibrator()->calibrate($a, $b));
    }
}
