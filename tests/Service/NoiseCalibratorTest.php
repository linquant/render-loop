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

    public function testUneInsertionStructurelleNInterromptPasLaDetectionDuBruitSuivant(): void
    {
        // B insère un bandeau avant l'horodatage : le décalage ne doit pas masquer
        // le bruit (l'horodatage volatil) situé APRÈS l'insertion. Le bandeau lui-même,
        // présent dans une capture et pas l'autre, EST du bruit structurel → proposé.
        $a = '<main id="contenu"><span class="horodatage">10:00:01</span></main>';
        $b = '<main id="contenu"><div class="bandeau">Promo</div><span class="horodatage">10:00:02</span></main>';

        self::assertSame(['.bandeau', '.horodatage'], $this->calibrator()->calibrate($a, $b));
    }

    public function testUneInsertionMultiElementsPorteuseDEnfantsNeCreePasDeFauxPositif(): void
    {
        // Cas réaliste : B insère un bloc promo PORTEUR D'ENFANTS (pas un simple div
        // vide) avant le contenu stable. L'appariement par keyPath doit :
        //  - NE PAS proposer l'élément stable `#stable` (aucune dérive) ;
        //  - retrouver le bruit (`.horodatage`) situé APRÈS l'insertion ;
        //  - proposer le bloc structurel inséré (`.promo`).
        $a = '<main id="contenu">'
            . '<p id="stable">Prix : 1295.00</p>'
            . '<span class="horodatage">10:00:01</span>'
            . '</main>';
        $b = '<main id="contenu">'
            . '<aside class="promo"><strong>Offre</strong><a href="/x">voir</a></aside>'
            . '<p id="stable">Prix : 1295.00</p>'
            . '<span class="horodatage">10:00:02</span>'
            . '</main>';

        $selecteurs = $this->calibrator()->calibrate($a, $b);

        self::assertNotContains('#stable', $selecteurs, 'un élément stable ne doit pas être proposé comme bruit');
        self::assertContains('.horodatage', $selecteurs, 'le bruit après l\'insertion doit rester détecté');
        self::assertContains('.promo', $selecteurs, 'le bloc inséré est du bruit structurel et doit être proposé');
    }

    public function testUnePageLourdementRestructureeNeInondePasLeMasque(): void
    {
        // Restructuration massive (tags entièrement remplacés) : aucun appariement
        // possible, donc une avalanche de candidats positionnels peu fiables. Le
        // garde-fou renonce (`[]`) plutôt que d'inonder le masque.
        $lignesA = '';
        $lignesB = '';
        for ($n = 0; $n < 60; $n++) {
            $lignesA .= '<p>ligne ' . $n . '</p>';
            $lignesB .= '<div>bloc ' . $n . '</div>';
        }
        $a = '<main id="contenu">' . $lignesA . '</main>';
        $b = '<main id="contenu">' . $lignesB . '</main>';

        self::assertSame([], $this->calibrator()->calibrate($a, $b));
    }

    public function testUnIdDupliqueNEcrasePasLElementVolatil(): void
    {
        // HTML malformé (que ce codebase traite) : deux éléments partagent le même
        // `id` — une horloge volatile et un prix stable. L'appariement par keyPath
        // ancré sur l'`id` leur donnait le même chemin, si bien que le volatil était
        // écrasé par le stable et son bruit passait inaperçu (`[]`). Un `id` dupliqué
        // ne doit donc pas servir d'ancre : le volatil reste détecté.
        $a = '<div><span id="val">10:00:01</span><span id="val">1295.00</span></div>';
        $b = '<div><span id="val">10:00:02</span><span id="val">1295.00</span></div>';

        self::assertCount(
            1,
            $this->calibrator()->calibrate($a, $b),
            'le volatil à id dupliqué doit rester détecté, pas écrasé par le stable',
        );
    }
}
