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

    /**
     * Deux captures d'un HTML choisi, pour éprouver l'assertion de portée.
     *
     * @return array{0: CommandTester, 1: string, 2: string}
     */
    private function deuxHtml(string $avant, string $apres): array
    {
        $repo = new SnapshotRepository($this->dir);
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $from = $repo->save(new Snapshot($url, 1000.0, $avant, $folder, 'avant'));
        $to = $repo->save(new Snapshot($url, 2000.0, $apres, $folder, 'apres'));

        return [new CommandTester(new HtmlCompareCommand()), $from, $to];
    }

    public function testLaCommandeEstNommeeRenderLoop(): void
    {
        $command = new HtmlCompareCommand();

        self::assertSame('render-loop', $command->getName());
        self::assertSame('Capture and compare the HTML state of pages (render regression net)', $command->getDescription());
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

        $tester->execute(
            ['--action' => 'inexistante', '--dir' => $this->dir],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unknown action', $tester->getErrorOutput());
    }

    public function testErreurJsonVaSurStderrEtLaisseStdoutVide(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(
            ['--action' => 'capture', '--format' => 'json', '--dir' => $this->dir],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('--url', $tester->getErrorOutput());
    }

    public function testListeSurvitAUnSnapshotCorrompu(): void
    {
        // #18 : un seul .json tronqué ne doit plus bloquer `list` pour toutes les
        // pages. La capture corrompue est sautée ; la bonne reste listée, exit 0.
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $repo = new SnapshotRepository($this->dir);
        $bon = $repo->save(new Snapshot($url, 1000.0, '<p>bon</p>', $folder, 'bon'));

        $dir = $this->dir . '/' . $folder;
        file_put_contents($dir . '/2000.0000.json', '{"url":');

        $tester = new CommandTester(new HtmlCompareCommand());
        $tester->execute(['--action' => 'list', '--dir' => $this->dir]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString($bon, $tester->getDisplay());
    }

    public function testCompareSurUnSnapshotCorrompuEchoueEnNommantLeFichier(): void
    {
        // #18 : une action qui CIBLE la capture corrompue (ici `compare`) ne doit
        // plus faire remonter un « Syntax error » brut, mais un message
        // actionnable sur stderr nommant le fichier fautif, stdout vide.
        $url = 'https://app.local/fiche.php';
        $folder = \RenderLoop\Service\UrlFolderNamer::toFolderName($url);
        $dir = $this->dir . '/' . $folder;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/1000.0000.json', '{"url":');

        $tester = new CommandTester(new HtmlCompareCommand());
        $tester->execute(
            [
                '--action' => 'compare',
                '--from' => $folder . '_1000.0000',
                '--to' => $folder . '_1000.0000',
                '--dir' => $this->dir,
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('1000.0000.json', $tester->getErrorOutput());
    }

    public function testMaskAddEcritLesSelecteursDansLaConfigCiblee(): void
    {
        $config = $this->tempConfig(['.phpdebugbar']);
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute([
            '--action' => 'mask-add',
            '--selector' => ['.chat-widget', '.cookie-banner'],
            '--config' => $config,
            '--dir' => $this->dir,
            '--format' => 'json',
        ]);

        $tester->assertCommandIsSuccessful();
        /** @var array{applied: list<string>} $out */
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['.chat-widget', '.cookie-banner'], $out['applied']);

        /** @var array{noise_selectors: list<string>} $written */
        $written = json_decode((string) file_get_contents($config), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['.phpdebugbar', '.chat-widget', '.cookie-banner'], $written['noise_selectors']);
    }

    public function testMaskAddNeDupliquePasUnSelecteurDejaPresent(): void
    {
        $config = $this->tempConfig(['.phpdebugbar']);
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute([
            '--action' => 'mask-add',
            '--selector' => ['.phpdebugbar', '.new-one'],
            '--config' => $config,
            '--dir' => $this->dir,
            '--format' => 'json',
        ]);

        $tester->assertCommandIsSuccessful();
        /** @var array{applied: list<string>} $out */
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['.new-one'], $out['applied']);
    }

    public function testMaskAddSansSelecteurEchoue(): void
    {
        $config = $this->tempConfig(['.phpdebugbar']);
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(
            ['--action' => 'mask-add', '--config' => $config, '--dir' => $this->dir],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('--selector', $tester->getErrorOutput());
    }

    public function testCapabilitiesJsonDecritToutesLesActions(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(['--action' => 'capabilities', '--format' => 'json', '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        /** @var array{tool: string, errors: string, actions: list<array{name: string, required: list<string>, output: string, exit_code: string}>} $manifest */
        $manifest = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('render-loop', $manifest['tool']);
        self::assertStringContainsString('stderr', $manifest['errors']);

        $names = array_map(static fn (array $a): string => $a['name'], $manifest['actions']);
        self::assertSame([
            'capture', 'store', 'recheck', 'compare', 'list',
            'calibrate', 'mask-add', 'capabilities', 'advise', 'golden-record', 'golden-verify',
        ], $names);

        foreach ($manifest['actions'] as $action) {
            self::assertArrayHasKey('required', $action);
            self::assertArrayHasKey('output', $action);
            self::assertArrayHasKey('exit_code', $action);
        }
    }

    public function testAdviseJsonExposeLeModeOperatoire(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(['--action' => 'advise', '--format' => 'json', '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        /** @var array{audience: string, one_liner: string, workflow: list<string>, reading_the_output: list<string>, pitfalls: list<string>} $advice */
        $advice = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('AI coding agents', $advice['audience']);
        self::assertNotEmpty($advice['workflow']);
        self::assertNotEmpty($advice['reading_the_output']);
        self::assertNotEmpty($advice['pitfalls']);
    }

    public function testAdviseTexteListeLesEtapes(): void
    {
        $tester = new CommandTester(new HtmlCompareCommand());

        $tester->execute(['--action' => 'advise', '--dir' => $this->dir]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('modus operandi', $display);
        self::assertStringContainsString('The loop to follow', $display);
        self::assertStringContainsString('Pitfalls', $display);
    }

    public function testExpectSelectorReussitQuandLeChangementEstConfine(): void
    {
        [$tester, $from, $to] = $this->deuxHtml(
            '<main id="contenu"><h1>Produit</h1><p class="prix">19,90</p></main>',
            '<main id="contenu"><h1>Produit</h1><p class="prix">24,90</p></main>',
        );

        $tester->execute([
            '--action' => 'compare',
            '--from' => $from,
            '--to' => $to,
            '--dir' => $this->dir,
            '--expect-selector' => ['.prix'],
        ]);

        $tester->assertCommandIsSuccessful();
    }

    public function testExpectSelectorEchoueQuandUnChangementFuitHorsDuSelecteur(): void
    {
        [$tester, $from, $to] = $this->deuxHtml(
            '<main id="contenu"><h1>Produit</h1><p class="prix">19,90</p></main>',
            '<main id="contenu"><h1>Promo</h1><p class="prix">24,90</p></main>',
        );

        $tester->execute([
            '--action' => 'compare',
            '--from' => $from,
            '--to' => $to,
            '--dir' => $this->dir,
            '--expect-selector' => ['.prix'],
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Promo', $tester->getDisplay());
    }

    public function testExpectSelectorEchoueQuandLeSelecteurNeMatcheRien(): void
    {
        [$tester, $from, $to] = $this->deuxHtml(
            '<main id="contenu"><p class="prix">19,90</p></main>',
            '<main id="contenu"><p class="prix">24,90</p></main>',
        );

        $tester->execute([
            '--action' => 'compare',
            '--from' => $from,
            '--to' => $to,
            '--dir' => $this->dir,
            '--expect-selector' => ['.inexistant'],
            '--format' => 'json',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        /** @var array{assertion: array{satisfied: bool, reason: string}} $out */
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($out['assertion']['satisfied']);
        self::assertStringContainsString('matched no element', $out['assertion']['reason']);
    }

    public function testExpectSelectorJsonExposeLaPortionHorsScope(): void
    {
        [$tester, $from, $to] = $this->deuxHtml(
            '<main id="contenu"><h1>Produit</h1><p class="prix">19,90</p></main>',
            '<main id="contenu"><h1>Promo</h1><p class="prix">24,90</p></main>',
        );

        $tester->execute([
            '--action' => 'compare',
            '--from' => $from,
            '--to' => $to,
            '--dir' => $this->dir,
            '--expect-selector' => ['.prix'],
            '--format' => 'json',
        ]);

        /** @var array{assertion: array{satisfied: bool, out_of_scope: array{diff: string}}} $out */
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($out['assertion']['satisfied']);
        // La fuite (le <h1>) est présente dans le diff hors portée…
        self::assertStringContainsString('Promo', $out['assertion']['out_of_scope']['diff']);
        // …mais le changement attendu (le prix) est bien masqué, pas répété comme une fuite.
        self::assertStringNotContainsString('24,90', $out['assertion']['out_of_scope']['diff']);
    }

    public function testExpectSelectorSatisfaitQuandRenduIdentiqueSignaleUnScopeInchange(): void
    {
        $html = '<main id="contenu"><p class="prix">19,90</p></main>';
        [$tester, $from, $to] = $this->deuxHtml($html, $html);

        $tester->execute([
            '--action' => 'compare',
            '--from' => $from,
            '--to' => $to,
            '--dir' => $this->dir,
            '--expect-selector' => ['.prix'],
            '--format' => 'json',
        ]);

        $tester->assertCommandIsSuccessful();
        /** @var array{assertion: array{satisfied: bool, inside_scope_changed: bool}} $out */
        $out = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($out['assertion']['satisfied']);
        self::assertFalse($out['assertion']['inside_scope_changed']);
    }

    public function testGoldenVerifyJsonExposeUnPayloadParCas(): void
    {
        // Un golden manquant suffit à exercer la sortie JSON sans aucune capture
        // réseau : verify() renvoie « missing » avant de toucher l'URL.
        $goldenDir = $this->dir . '/golden';
        mkdir($goldenDir, 0775, true);
        file_put_contents(
            $goldenDir . '/shop.json',
            '{"name":"shop","cases":[{"id":"accueil","url":"http://localhost/shop.php"}],"mask":[]}'
        );

        $tester = new CommandTester(new HtmlCompareCommand());
        $tester->execute([
            '--action' => 'golden-verify',
            '--suite' => 'shop',
            '--golden-dir' => $goldenDir,
            '--format' => 'json',
        ]);

        // Régression golden => code de sortie non nul.
        self::assertNotSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        /** @var array{status: string, changed: int, missing: int, cases: list<array{id: string, status: string, summary: mixed, diff: mixed}>} $out */
        $out = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('regression', $out['status']);
        self::assertSame(0, $out['changed']);
        self::assertSame(1, $out['missing']);
        self::assertCount(1, $out['cases']);
        self::assertSame('accueil', $out['cases'][0]['id']);
        self::assertSame('missing', $out['cases'][0]['status']);
        self::assertNull($out['cases'][0]['summary']);
        self::assertNull($out['cases'][0]['diff']);
    }

    public function testGoldenVerifyJsonCasOkExposeSummaryEtDiffNull(): void
    {
        // Un cas « ok » (golden ré-vérifié inchangé) porte un DiffResult IDENTIQUE
        // mais NON null : on vérifie que summary/diff restent bien `null` dans le
        // JSON, conformément au contrat annoncé (capabilities + docstring). C'est
        // exactement le cas que le bug de contrat classait à tort comme peuplé.
        $docroot = $this->dir . '/www';
        mkdir($docroot, 0775, true);
        file_put_contents(
            $docroot . '/index.html',
            '<!doctype html><html><body><main><h1>Boutique</h1><p>Stable</p></main></body></html>'
        );

        [$proc, $port] = $this->demarreServeur($docroot);
        if ($proc === null) {
            self::markTestSkipped('Serveur HTTP local indisponible.');
        }

        try {
            $goldenDir = $this->dir . '/golden';
            mkdir($goldenDir, 0775, true);
            $url = "http://127.0.0.1:{$port}/";
            file_put_contents(
                $goldenDir . '/shop.json',
                json_encode([
                    'name' => 'shop',
                    'cases' => [['id' => 'accueil', 'url' => $url]],
                    'mask' => [],
                ], JSON_THROW_ON_ERROR)
            );

            $record = new CommandTester(new HtmlCompareCommand());
            $record->execute([
                '--action' => 'golden-record',
                '--suite' => 'shop',
                '--golden-dir' => $goldenDir,
            ]);
            $record->assertCommandIsSuccessful();

            $verify = new CommandTester(new HtmlCompareCommand());
            $verify->execute([
                '--action' => 'golden-verify',
                '--suite' => 'shop',
                '--golden-dir' => $goldenDir,
                '--format' => 'json',
            ]);

            // Aucun écart => succès (code 0).
            self::assertSame(0, $verify->getStatusCode());
            /** @var array{status: string, changed: int, missing: int, cases: list<array{id: string, status: string, summary: mixed, diff: mixed}>} $out */
            $out = json_decode($verify->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('ok', $out['status']);
            self::assertSame(0, $out['changed']);
            self::assertSame(0, $out['missing']);
            self::assertCount(1, $out['cases']);
            self::assertSame('accueil', $out['cases'][0]['id']);
            self::assertSame('ok', $out['cases'][0]['status']);
            self::assertNull($out['cases'][0]['summary']);
            self::assertNull($out['cases'][0]['diff']);
        } finally {
            $this->arreteServeur($proc);
        }
    }

    /**
     * Démarre un serveur HTTP PHP local servant $docroot sur un port libre.
     *
     * @return array{0: ?resource, 1: int}
     */
    private function demarreServeur(string $docroot): array
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return [null, 0];
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false) {
            return [null, 0];
        }
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $proc = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return [null, 0];
        }

        // Attendre que le serveur accepte les connexions (max ~5 s).
        for ($i = 0; $i < 50; $i++) {
            $client = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($client)) {
                fclose($client);

                return [$proc, $port];
            }
            usleep(100000);
        }

        proc_terminate($proc);
        proc_close($proc);

        return [null, 0];
    }

    /**
     * @param resource|null $proc
     */
    private function arreteServeur($proc): void
    {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * Régression #19 : un `--expect-selector` syntaxiquement invalide est rejeté
     * à la frontière CLI, avec un message qui nomme l'option — avant toute capture
     * (ici via compare, qui ne touche pas le réseau).
     */
    public function testExpectSelectorInvalideEchoueEnNommantLOption(): void
    {
        [$tester, $from, $to] = $this->deuxHtml('<div>a</div>', '<div>b</div>');

        $tester->execute(
            [
                '--action' => 'compare',
                '--from' => $from,
                '--to' => $to,
                '--dir' => $this->dir,
                '--expect-selector' => ['div[['],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--expect-selector', $tester->getErrorOutput());
    }

    /**
     * @param list<string> $noise
     */
    private function tempConfig(array $noise): string
    {
        $path = $this->dir . '/normalizer.json';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        file_put_contents($path, json_encode(['root_selector' => null, 'noise_selectors' => $noise]) . "\n");

        return $path;
    }
}
