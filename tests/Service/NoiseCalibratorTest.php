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
}
