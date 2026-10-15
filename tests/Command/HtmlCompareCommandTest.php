<?php

declare(strict_types=1);

namespace RenderLoop\Tests\Command;

use RenderLoop\Command\HtmlCompareCommand;
use RenderLoop\Model\Snapshot;
use RenderLoop\Model\SnapshotRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class HtmlCompareCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htmlcompare_cmd_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @return array{0: CommandTester, 1: string, 2: string}
     */
    private function deuxCaptures(): array
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $avant = $repo->save(new Snapshot($url, 1000.0, '<main id="contenu"><p class="prix">19,90</p></main>', $folder, 'avant'));
        $apres = $repo->save(new Snapshot($url, 2000.0, '<main id="contenu"><p class="prix">24,90</p></main>', $folder, 'apres'));

        return [new CommandTester(new HtmlCompareCommand()), $avant, $apres];
    }

    public function testCompareAfficheLeChangement(): void
    {
        [$tester, $avant, $apres] = $this->deuxCaptures();

        $tester->execute(['--action' => 'compare', '--from' => $avant, '--to' => $apres, '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('24,90', $tester->getDisplay());
    }

    public function testListeJsonContientLesDeuxCaptures(): void
    {
        [$tester, $avant, $apres] = $this->deuxCaptures();

        $tester->execute(['--action' => 'list', '--dir' => $this->dir, '--format' => 'json']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString($avant, $display);
        self::assertStringContainsString($apres, $display);
    }

    public function testExitCodeEchoueQuandLeDiffEstNonVide(): void
    {
        [$tester, $avant, $apres] = $this->deuxCaptures();

        $tester->execute(['--action' => 'compare', '--from' => $avant, '--to' => $apres, '--dir' => $this->dir, '--exit-code' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('24,90', $tester->getDisplay());
    }

    public function testExitCodeReussitQuandLeRenduEstIdentique(): void
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $html = '<main id="contenu"><p class="prix">19,90</p></main>';
        $a = $repo->save(new Snapshot($url, 1000.0, $html, $folder, 'avant'));
        $b = $repo->save(new Snapshot($url, 2000.0, $html, $folder, 'apres'));

        $tester = new CommandTester(new HtmlCompareCommand());
        $tester->execute(['--action' => 'compare', '--from' => $a, '--to' => $b, '--dir' => $this->dir, '--exit-code' => true]);

        $tester->assertCommandIsSuccessful();
    }

    public function testSansExitCodeLeDiffNonVideResteUnSucces(): void
    {
        [$tester, $avant, $apres] = $this->deuxCaptures();

        $tester->execute(['--action' => 'compare', '--from' => $avant, '--to' => $apres, '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
    }

    public function testActionInconnueEchoue(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(['--action' => 'inexistante', '--dir' => $this->dir]);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testCaptureSansUrlEchoue(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(['--action' => 'capture', '--dir' => $this->dir]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--url', $tester->getDisplay());
    }
}
