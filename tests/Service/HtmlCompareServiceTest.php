<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Service;

use RenderLoop\Model\SnapshotRepository;
use RenderLoop\Service\HtmlCompareService;
use RenderLoop\Service\HtmlDiffer;
use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NormalizerConfig;
use RenderLoop\Service\PushedHtmlStrategy;
use PHPUnit\Framework\TestCase;

class HtmlCompareServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htmlcompare_svc_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private function service(): HtmlCompareService
    {
        return new HtmlCompareService(
            new SnapshotRepository($this->dir),
            new HtmlNormalizer(NormalizerConfig::default()),
            new HtmlDiffer(),
        );
    }

    /**
     * Deux recontrôles consécutifs comparent tous deux à la baseline posée
     * délibérément (« avant »), jamais à la capture du recontrôle précédent.
     */
    public function testLesRecontrolesNeDeriventPasDeLaBaseline(): void
    {
        $service = $this->service();
        $url = 'http://app.local/fiche.php';

        $baseline = $service->capture($url, new PushedHtmlStrategy('<main><p>19,90</p></main>'), 'avant');
        $r1 = $service->recheck($url, new PushedHtmlStrategy('<main><p>24,90</p></main>'));
        $r2 = $service->recheck($url, new PushedHtmlStrategy('<main><p>24,90</p></main>'));

        self::assertSame($baseline->id(), $r1->baseline->id());
        self::assertSame($baseline->id(), $r2->baseline->id(), 'Le 2e recontrôle a dérivé vers une autre baseline');
        self::assertFalse($r1->diff->isIdentical());
        self::assertFalse($r2->diff->isIdentical());
    }

    public function testRecheckSansBaselineLeveUneErreurAttendue(): void
    {
        $this->expectException(\RenderLoop\HtmlCompareException::class);

        $this->service()->recheck('http://app.local/x.php', new PushedHtmlStrategy('<p>x</p>'));
    }

    public function testCompareSnapshotsInconnuLeveUneErreurAttendue(): void
    {
        $this->expectException(\RenderLoop\HtmlCompareException::class);

        $this->service()->compareSnapshots('inexistant_1', 'inexistant_2');
    }

    public function testDefinirUneReferenceExpliciteRemplaceLImplicite(): void
    {
        $service = $this->service();
        $url = 'http://app.local/fiche.php';

        $ancienne = $service->capture($url, new PushedHtmlStrategy('<p>v1</p>'), 'avant');
        $recente = $service->capture($url, new PushedHtmlStrategy('<p>v2</p>'), 'avant');

        // Par défaut, la plus récente non-recheck est la référence.
        $implicite = $service->latestBaseline($url);
        self::assertNotNull($implicite);
        self::assertSame($recente->id(), $implicite->id());

        // On fige explicitement l'ancienne comme référence.
        $service->setBaseline($url, $ancienne->id());
        $explicite = $service->latestBaseline($url);
        self::assertNotNull($explicite);
        self::assertSame($ancienne->id(), $explicite->id());
    }

    public function testSupprimerUneCapture(): void
    {
        $service = $this->service();
        $url = 'http://app.local/p.php';
        $snap = $service->capture($url, new PushedHtmlStrategy('<p>x</p>'), 'avant');

        self::assertTrue($service->deleteSnapshot($snap->id()));
        self::assertNull($service->getSnapshot($snap->id()));
    }

    public function testVoirUneCaptureRenvoieLeHtmlNormalise(): void
    {
        $service = $this->service();
        $url = 'http://app.local/p.php';
        $snap = $service->capture($url, new PushedHtmlStrategy('<main><p>Bonjour</p><script>var x=1;</script></main>'), 'avant');

        $view = $service->viewSnapshot($snap->id());

        self::assertNotNull($view);
        self::assertStringContainsString('Bonjour', $view['html']);
        self::assertStringNotContainsString('var x', $view['html']); // script masqué par la normalisation
    }
}
