<?php

declare(strict_types=1);

namespace RenderLoop\Command;

use RenderLoop\Model\GoldenManifest;
use RenderLoop\Model\Snapshot;
use RenderLoop\Model\SnapshotRepository;
use RenderLoop\Service\Change;
use RenderLoop\Service\CurlCaptureStrategy;
use RenderLoop\Service\DiffResult;
use RenderLoop\Service\GitRevision;
use RenderLoop\Service\GoldenMaster;
use RenderLoop\Service\HtmlCompareService;
use RenderLoop\Service\HtmlDiffer;
use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NoiseConfigWriter;
use RenderLoop\Service\NormalizerConfig;
use RenderLoop\Service\PushedHtmlStrategy;
use RenderLoop\Service\ScopeAssertion;
use RenderLoop\Service\ScopeAssertionChecker;
use RenderLoop\Service\UrlGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'render-loop',
    description: 'Capture and compare the HTML state of pages (render regression net)',
)]
class HtmlCompareCommand extends Command
{
    private const DEFAULT_DIR = '.render-loop/snapshots';

    // Delay between the two calibration captures: crosses a one-second boundary
    // so that "now" timestamps (temporal noise) actually differ and get caught.
    private const CALIBRATION_DELAY_MICROS = 1_100_000;

    protected function configure(): void
    {
        $this
            ->addOption('action', 'a', InputOption::VALUE_REQUIRED, 'capture | store | recheck | compare | list | calibrate | mask-add | golden-record | golden-verify | capabilities | advise', 'recheck')
            ->addOption('suite', null, InputOption::VALUE_REQUIRED, 'Golden suite name (manifest <golden-dir>/<suite>.json)')
            ->addOption('golden-dir', null, InputOption::VALUE_REQUIRED, 'Golden suites directory', 'golden')
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Page URL')
            ->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Snapshot label (e.g. before)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Baseline snapshot id (compare)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Current snapshot id (compare)')
            ->addOption('allow-host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Host allowed in addition to localhost', [])
            ->addOption('cookie-name', null, InputOption::VALUE_REQUIRED, 'Session cookie name used for authenticated capture', 'PHPSESSID')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Snapshot storage directory', self::DEFAULT_DIR)
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table | json', 'table')
            ->addOption('max-hunks', null, InputOption::VALUE_REQUIRED, 'Maximum number of hunks displayed', '40')
            ->addOption('exit-code', null, InputOption::VALUE_NONE, 'Exit with a non-zero status when the diff is non-empty (recheck/compare), like `git diff --exit-code`')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Normalizer config file (noise selectors); defaults to the bundled config/normalizer.json')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Number of calibration captures (>= 2); more catches slower-moving noise', '2')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'calibrate: append the discovered selectors straight into the normalizer config')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'mask-add: a noise selector to ignore (repeatable)', [])
            ->addOption('expect-selector', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'recheck/compare: assert the change stays within this selector; exit non-zero (and report the leak) on any change outside it (repeatable)', [])
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'capture/recheck: HTTP method to use (e.g. POST); defaults to GET, or POST when --data is set')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'capture/recheck: extra request header "Name: value" (repeatable), e.g. to replay an internal AJAX endpoint', [])
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'capture/recheck: request body (implies POST unless --method is set)')
            ->addOption('ajax', null, InputOption::VALUE_NONE, 'capture/recheck: shorthand adding the "X-Requested-With: XMLHttpRequest" header (legacy AJAX endpoints)')
            ->setHelp(<<<'HELP'
Detects the changes of a web page between two moments.

<info>Human workflow:</info>
  1. <comment>render-loop -a capture -u "http://localhost/page.php" -t before</comment>
  2. (you modify the page)
  3. <comment>render-loop -a recheck -u "http://localhost/page.php"</comment>

<info>Assert the blast radius</info> (declare the only selector your edit may touch;
exit != 0 if anything changes outside it, with the leak reported):
  <comment>render-loop -a recheck -u "http://localhost/page.php" --expect-selector=".price"</comment>

<info>JS/SPA pages</info> (the rendered HTML is pushed via STDIN by a headless browser):
  <comment>cat rendered.html | render-loop -a store -u "http://localhost/app" -t before</comment>

<info>Legacy pages fed by an internal AJAX call</info> (snapshot the fragment endpoint
directly — no browser needed; --ajax adds the X-Requested-With header):
  <comment>render-loop -a capture -u "http://localhost/ajax/price.php?id=42" --ajax -t before</comment>
  <comment>render-loop -a capture -u "http://localhost/search" --method POST --data "q=bag&page=2" --ajax</comment>

<info>Other actions:</info>
  compare --from=<id> --to=<id>   diff between two precise snapshots
  list [--url=...]                list snapshots
  calibrate --url=...             capture the page twice and suggest the noise selectors to mask
                                  (--samples=N to capture more; --apply to write them to the config)
  mask-add --selector=...        add your own noise selectors to the config (repeat --selector)
  capabilities                   machine-readable contract of every action (-f json); for AI agents
  advise                         modus operandi + pitfalls for an AI agent driving the loop (-f json)

<info>Golden master (legacy pages without tests):</info>
  golden-record --suite=page   pin the current rendered output of the cases in golden/page.json
  golden-verify --suite=page   re-capture and compare to the golden (exit != 0 on render regression)

<info>Authenticated pages:</info> pass the session cookie value via the RENDERLOOP_COOKIE
environment variable (never as a CLI argument, which would be visible in `ps`):
  <comment>RENDERLOOP_COOKIE=<value> render-loop -a capture -u "http://localhost/page.php"</comment>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $service = $this->buildService($input);
            $action = $this->stringOption($input, 'action', 'recheck');

            return match ($action) {
                'capture' => $this->doCapture($input, $output, $service),
                'store' => $this->doStore($input, $output, $service),
                'recheck' => $this->doRecheck($input, $output, $service),
                'compare' => $this->doCompare($input, $output, $service),
                'list' => $this->doList($input, $output, $service),
                'calibrate' => $this->doCalibrate($input, $output, $service),
                'mask-add' => $this->doMaskAdd($input, $output),
                'capabilities' => $this->doCapabilities($input, $output),
                'advise' => $this->doAdvise($input, $output),
                'golden-record' => $this->doGoldenRecord($input, $output),
                'golden-verify' => $this->doGoldenVerify($input, $output),
                default => $this->fail($output, "Unknown action: {$action}"),
            };
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function doCapture(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $snapshot = $service->capture(
            $this->requireOption($input, 'url'),
            $this->curlStrategy($input),
            $this->optionalString($input, 'tag'),
            GitRevision::current(),
        );
        $output->writeln("<info>Snapshot saved</info>: {$snapshot->id()}");

        return self::SUCCESS;
    }

    private function doStore(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $html = stream_get_contents(STDIN);
        if (!is_string($html) || trim($html) === '') {
            return $this->fail($output, 'No HTML received on STDIN (expected: the rendered HTML of the page).');
        }

        $snapshot = $service->capture(
            $this->requireOption($input, 'url'),
            new PushedHtmlStrategy($html),
            $this->optionalString($input, 'tag') ?? 'store',
            GitRevision::current(),
        );
        $output->writeln("<info>Pushed HTML saved</info>: {$snapshot->id()}");

        return self::SUCCESS;
    }

    private function doRecheck(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $result = $service->recheck(
            $this->requireOption($input, 'url'),
            $this->curlStrategy($input),
            GitRevision::current(),
        );

        $assertion = $this->scopeAssertion($input, $result->baseline->html, $result->current->html);

        return $this->renderDiff($input, $output, $result->diff, "{$result->baseline->id()} -> {$result->current->id()}", $assertion);
    }

    private function doCompare(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $from = $this->requireOption($input, 'from');
        $to = $this->requireOption($input, 'to');

        $diff = $service->compareSnapshots($from, $to);

        // compareSnapshots a déjà levé si une capture manque : les deux existent ici.
        $fromSnapshot = $service->getSnapshot($from);
        $toSnapshot = $service->getSnapshot($to);
        $assertion = ($fromSnapshot !== null && $toSnapshot !== null)
            ? $this->scopeAssertion($input, $fromSnapshot->html, $toSnapshot->html)
            : null;

        return $this->renderDiff($input, $output, $diff, "{$from} -> {$to}", $assertion);
    }

    private function doList(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $snapshots = $service->listSnapshots($this->optionalString($input, 'url'));

        if ($snapshots === []) {
            $output->writeln('<comment>No snapshot.</comment>');

            return self::SUCCESS;
        }

        if ($this->isJson($input)) {
            $rows = array_map(static fn(Snapshot $s): array => [
                'id' => $s->id(),
                'url' => $s->url,
                'tag' => $s->tag,
                'datetime' => date('Y-m-d H:i:s', $s->timestamp),
            ], $snapshots);
            $output->writeln($this->json($rows));

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Id', 'Date', 'Tag', 'URL']);
        foreach ($snapshots as $snapshot) {
            $table->addRow([
                $snapshot->id(),
                date('Y-m-d H:i:s', $snapshot->timestamp),
                $snapshot->tag ?? '-',
                $this->truncate($snapshot->url, 50),
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }

    private function doCalibrate(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $samples = max(2, $this->intOption($input, 'samples', 2));
        $selectors = $service->calibrate(
            $this->requireOption($input, 'url'),
            $this->curlStrategy($input),
            self::CALIBRATION_DELAY_MICROS,
            $samples,
        );

        $apply = $input->getOption('apply') === true;
        $applied = ($apply && $selectors !== [])
            ? (new NoiseConfigWriter())->append($this->configPath($input), $selectors)
            : [];

        if ($this->isJson($input)) {
            $output->writeln($this->json(['candidates' => $selectors, 'applied' => $applied]));

            return self::SUCCESS;
        }

        if ($selectors === []) {
            $output->writeln('<info>No noise detected</info>: identical captures, nothing to mask.');

            return self::SUCCESS;
        }

        if ($apply) {
            $output->writeln(sprintf('<comment>Noise selectors written to %s:</comment>', $this->configPath($input)));
            foreach ($selectors as $selector) {
                $isNew = in_array($selector, $applied, true);
                $output->writeln(sprintf('  %s %s%s', $isNew ? '+' : '=', $selector, $isNew ? '' : ' (already present)'));
            }

            return self::SUCCESS;
        }

        $output->writeln('<comment>Candidate noise selectors</comment> (add them to the config, or re-run with --apply):');
        foreach ($selectors as $selector) {
            $output->writeln('  - ' . $selector);
        }

        return self::SUCCESS;
    }

    /**
     * Laisse un agent déclarer SES propres sélecteurs de bruit, sans calibration :
     * il sait qu'un widget de chat ou une bannière est du bruit → il le masque.
     */
    private function doMaskAdd(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('selector');
        $selectors = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
        if ($selectors === []) {
            throw new \InvalidArgumentException('mask-add requires at least one --selector');
        }

        $applied = (new NoiseConfigWriter())->append($this->configPath($input), $selectors);

        if ($this->isJson($input)) {
            $output->writeln($this->json(['applied' => $applied]));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('<comment>Noise selectors written to %s:</comment>', $this->configPath($input)));
        foreach ($selectors as $selector) {
            $isNew = in_array($selector, $applied, true);
            $output->writeln(sprintf('  %s %s%s', $isNew ? '+' : '=', $selector, $isNew ? '' : ' (already present)'));
        }

        return self::SUCCESS;
    }

    /**
     * Contrat machine : la liste des actions avec, pour chacune, son but, ses
     * options requises/optionnelles, la forme de sa sortie JSON et la sémantique de
     * son code de retour. Pensé pour qu'un agent sache quoi appeler sans parser le
     * texte d'aide. `-f json` (défaut implicite ici) renvoie le manifeste ; sinon un
     * résumé lisible renvoie vers `-f json`.
     */
    private function doCapabilities(InputInterface $input, OutputInterface $output): int
    {
        $manifest = $this->capabilitiesManifest();

        if ($this->isJson($input)) {
            $output->writeln($this->json($manifest));

            return self::SUCCESS;
        }

        $output->writeln('<comment>Actions</comment> (run with <info>-a capabilities -f json</info> for the full machine contract):');
        foreach ($manifest['actions'] as $action) {
            $required = $action['required'] === [] ? '' : ' (requires ' . implode(', ', $action['required']) . ')';
            $output->writeln(sprintf('  <info>%s</info>%s — %s', $action['name'], $required, $action['summary']));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{tool: string, formats: list<string>, errors: string, exit_codes: string, actions: list<array{name: string, summary: string, required: list<string>, optional: list<string>, output: string, exit_code: string}>}
     */
    private function capabilitiesManifest(): array
    {
        $capture = ['url', 'tag', 'dir', 'config', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'];
        $diffOut = 'with -f json: {"summary":{"lines_added":int,"lines_removed":int,"hunks":int,"truncated":bool,"tags_touched":[string],"changes":[{"kind":"text|added|removed","selector":string,"context":string|null,"before":string|null,"after":string|null}]},"diff":string}. With --expect-selector it also carries "assertion":{"expected_selectors":[string],"satisfied":bool,"inside_scope_changed":bool,"reason":string,"out_of_scope":{<a diff summary>,"diff":string}}.';

        return [
            'tool' => 'render-loop',
            'formats' => ['table', 'json'],
            'errors' => 'stderr; stdout stays empty on error, including with -f json.',
            'exit_codes' => '0 = success. recheck/compare return 0 even with a diff unless --exit-code is passed; golden-verify returns non-zero on a render regression; any action returns non-zero on error.',
            'actions' => [
                [
                    'name' => 'capture',
                    'summary' => 'Fetch a page (curl) and store its rendered HTML as a "before" snapshot. --method/--header/--data/--ajax replay an internal AJAX endpoint directly.',
                    'required' => ['url'],
                    'optional' => ['tag', 'dir', 'config', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'],
                    'output' => 'text: the saved snapshot id.',
                    'exit_code' => '0 on success, non-zero on error.',
                ],
                [
                    'name' => 'store',
                    'summary' => 'Store HTML piped on STDIN as a snapshot (for JS/SPA pages rendered elsewhere).',
                    'required' => ['url'],
                    'optional' => ['tag', 'dir', 'config'],
                    'output' => 'text: the saved snapshot id. (HTML is read from STDIN.)',
                    'exit_code' => '0 on success, non-zero on error or empty STDIN.',
                ],
                [
                    'name' => 'recheck',
                    'summary' => 'Re-capture the page and diff it against its latest snapshot — only the real change should surface.',
                    'required' => ['url'],
                    'optional' => ['dir', 'config', 'format', 'max-hunks', 'exit-code', 'expect-selector', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'],
                    'output' => $diffOut,
                    'exit_code' => '0 by default even when the diff is non-empty; non-zero on a non-empty diff only with --exit-code. With --expect-selector the scope assertion drives it: 0 when every change is confined to the selector(s), non-zero otherwise.',
                ],
                [
                    'name' => 'compare',
                    'summary' => 'Diff two precise snapshots by id.',
                    'required' => ['from', 'to'],
                    'optional' => ['dir', 'config', 'format', 'max-hunks', 'exit-code', 'expect-selector'],
                    'output' => $diffOut,
                    'exit_code' => '0 by default even when the diff is non-empty; non-zero on a non-empty diff only with --exit-code. With --expect-selector the scope assertion drives it: 0 when every change is confined to the selector(s), non-zero otherwise.',
                ],
                [
                    'name' => 'list',
                    'summary' => 'List stored snapshots, optionally filtered by url.',
                    'required' => [],
                    'optional' => ['url', 'dir', 'format'],
                    'output' => 'with -f json: [{"id":string,"url":string,"tag":string|null,"datetime":string}]',
                    'exit_code' => '0.',
                ],
                [
                    'name' => 'calibrate',
                    'summary' => 'Capture the page several times unchanged; whatever differs is noise. Suggests (or, with --apply, writes) the selectors to mask.',
                    'required' => ['url'],
                    'optional' => ['samples', 'apply', 'config', 'dir', 'format', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'],
                    'output' => 'with -f json: {"candidates":[string],"applied":[string]}',
                    'exit_code' => '0.',
                ],
                [
                    'name' => 'mask-add',
                    'summary' => 'Add your own noise selectors to the config without calibration (idempotent).',
                    'required' => ['selector'],
                    'optional' => ['config', 'format'],
                    'output' => 'with -f json: {"applied":[string]} (only the selectors actually added).',
                    'exit_code' => '0 on success, non-zero if no --selector is given.',
                ],
                [
                    'name' => 'capabilities',
                    'summary' => 'This manifest: the machine-readable contract of every action.',
                    'required' => [],
                    'optional' => ['format'],
                    'output' => 'with -f json: this document.',
                    'exit_code' => '0.',
                ],
                [
                    'name' => 'advise',
                    'summary' => 'Modus operandi and pitfalls for an AI agent driving the loop (the "how", where capabilities is the "what").',
                    'required' => [],
                    'optional' => ['format'],
                    'output' => 'with -f json: {"audience":string,"one_liner":string,"workflow":[string],"reading_the_output":[string],"pitfalls":[string]}',
                    'exit_code' => '0.',
                ],
                [
                    'name' => 'golden-record',
                    'summary' => 'Pin the current rendered output of a suite of cases (approval/characterization testing).',
                    'required' => ['suite'],
                    'optional' => ['golden-dir', 'config', 'dir', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'],
                    'output' => 'text: number of cases pinned and mask size.',
                    'exit_code' => '0 on success, non-zero on error.',
                ],
                [
                    'name' => 'golden-verify',
                    'summary' => 'Re-capture each case of a suite and compare to its golden; fails on any render drift.',
                    'required' => ['suite'],
                    'optional' => ['golden-dir', 'config', 'dir', 'format', 'allow-host', 'cookie-name', 'method', 'header', 'data', 'ajax'],
                    'output' => 'text/json per case (OK / CHANGED + summary / missing).',
                    'exit_code' => 'non-zero if any case changed or is missing a golden; 0 when all match.',
                ],
            ],
        ];
    }

    private function doAdvise(InputInterface $input, OutputInterface $output): int
    {
        $advice = $this->adviceManifest();

        if ($this->isJson($input)) {
            $output->writeln($this->json($advice));

            return self::SUCCESS;
        }

        $output->writeln("<comment>RenderLoop — modus operandi for an AI agent</comment>");
        $output->writeln("<info>{$advice['one_liner']}</info>");
        $output->writeln('');
        $this->writeAdviceSection($output, 'The loop to follow', $advice['workflow'], true);
        $this->writeAdviceSection($output, 'Reading the output', $advice['reading_the_output'], false);
        $this->writeAdviceSection($output, 'Pitfalls', $advice['pitfalls'], false);
        $output->writeln('Run <info>-a capabilities -f json</info> for the machine-readable contract of every action.');

        return self::SUCCESS;
    }

    /**
     * @param list<string> $items
     */
    private function writeAdviceSection(OutputInterface $output, string $title, array $items, bool $numbered): void
    {
        $output->writeln("<comment>{$title}</comment>");
        foreach ($items as $i => $item) {
            $bullet = $numbered ? sprintf('%d.', $i + 1) : '-';
            $output->writeln("  {$bullet} {$item}");
        }
        $output->writeln('');
    }

    /**
     * @return array{audience: string, one_liner: string, workflow: list<string>, reading_the_output: list<string>, pitfalls: list<string>}
     */
    private function adviceManifest(): array
    {
        return [
            'audience' => 'AI coding agents',
            'one_liner' => 'Prove your edit did exactly what you intended, and nothing else.',
            'workflow' => [
                'Discover the CLI before guessing: `-a capabilities -f json` returns every action, its required options, output shape and exit-code semantics.',
                'Capture the state BEFORE you touch anything: `-a capture -u <url> -t before`. No "before" snapshot means nothing to diff against.',
                'Make your change, then make sure the server actually serves the new file (watch opcache / build steps) before re-checking.',
                'Re-check: `-a recheck -u <url> -f json`. The diff should contain ONLY your intended change — anything else is a regression (an indirect effect, a shared include, concurrent edit).',
                'Assert the blast radius instead of eyeballing the diff: declare the only selector your edit may touch with `--expect-selector=<css>` (repeatable). You get a binary verdict (`assertion.satisfied`) and, on failure, the isolated out-of-scope leak + a non-zero exit. Declaring the intent BEFORE you see the diff is what stops you rationalizing an unexpected change away.',
                'For JS/SPA pages, render the HTML elsewhere and push it: `cat rendered.html | render-loop -a store -u <url> -t before`.',
                'For a legacy page whose data arrives via an internal AJAX call, do not snapshot the shell (the data is not in it): snapshot the fragment endpoint directly with `-a capture -u <endpoint> --ajax` (adds the X-Requested-With header), or add `--method POST --data "..."` for a POST endpoint. No browser needed when the AJAX call is a plain HTTP request.',
            ],
            'reading_the_output' => [
                'Read `summary` first (lines_added, lines_removed, hunks, tags_touched, changes). Unfold the full `diff` only when the summary is non-empty — it keeps your token budget low.',
                'The `changes` array is the semantic layer: one entry per localized change (kind/selector/context/before/after), so you need not parse the unified diff to know what happened.',
                'Decide from the JSON summary, not the exit code: recheck/compare return 0 even with a diff. Pass `--exit-code` if you want a non-zero status on a non-empty diff, like `git diff --exit-code`.',
            ],
            'pitfalls' => [
                'Masking is always by selector, never by regex on content: a price dropping to 0.00 must stay visible in the diff, not be erased as noise.',
                'Point `-c/--config` at YOUR project config, not the one bundled under vendor/ (read-only). You can declare your own noise without calibrating: `-a mask-add --selector=.chat-widget`.',
                '`changes` is best-effort on messy markup: a structural insertion that shifts positional paths makes it fall back to empty. The unified `diff` is the signal you can always rely on.',
                'It covers rendering only: a DB write, an export, a PDF or an API response stays invisible. For a critical computation, extract the function and unit-test it — the loop complements tests, it does not replace them.',
                '`--expect-selector` is an ephemeral per-edit guard, not a frozen regression net (that is golden-record/golden-verify). `satisfied:true` only means no visible change leaked outside the selector — not that the edit is correct, and not that non-rendered effects are fine. A wrong/missing selector shows up as satisfied:false with "matched no element", not a false green.',
                'Authenticated pages: pass the session cookie value via the RENDERLOOP_COOKIE environment variable, never as a CLI argument (it would be visible in `ps`).',
            ],
        ];
    }

    private function doGoldenRecord(InputInterface $input, OutputInterface $output): int
    {
        $manifest = GoldenManifest::fromJsonFile($this->manifestPath($input));
        $this->goldenMaster($input)->record($manifest, $this->curlStrategy($input));

        $output->writeln(sprintf(
            '<info>Golden recorded</info>: %d case(s), noise mask = %d selector(s). Remember to commit the golden directory.',
            count($manifest->cases()),
            count($manifest->mask()),
        ));

        return self::SUCCESS;
    }

    private function doGoldenVerify(InputInterface $input, OutputInterface $output): int
    {
        $manifest = GoldenManifest::fromJsonFile($this->manifestPath($input));
        $results = $this->goldenMaster($input)->verify($manifest, $this->curlStrategy($input));

        $changed = 0;
        $missing = 0;
        foreach ($results as $result) {
            switch ($result->status) {
                case 'ok':
                    $output->writeln("<info>OK</info> {$result->id}");
                    break;
                case 'missing':
                    $missing++;
                    $output->writeln("<comment>? {$result->id}</comment> — golden missing (run golden-record)");
                    break;
                default:
                    $changed++;
                    $summary = $result->diff?->summary() ?? [];
                    $output->writeln("<error>CHANGED {$result->id}</error> — " . json_encode($summary, JSON_UNESCAPED_UNICODE));
                    if ($result->diff !== null) {
                        $this->writeUnifiedDiff($output, $result->diff->unifiedDiff);
                    }
            }
        }

        if ($changed === 0 && $missing === 0) {
            $output->writeln('<info>Golden OK</info>: no render regression.');

            return self::SUCCESS;
        }

        $output->writeln("<error>Golden regression</error>: {$changed} changed case(s), {$missing} missing golden(s).");

        return self::FAILURE;
    }

    private function goldenMaster(InputInterface $input): GoldenMaster
    {
        return new GoldenMaster(
            new HtmlDiffer(max(1, $this->intOption($input, 'max-hunks', 40))),
            $this->stringOption($input, 'golden-dir', 'golden'),
            self::CALIBRATION_DELAY_MICROS,
            $this->loadConfig($input),
        );
    }

    private function manifestPath(InputInterface $input): string
    {
        return rtrim($this->stringOption($input, 'golden-dir', 'golden'), '/') . '/' . $this->requireOption($input, 'suite') . '.json';
    }

    private function buildService(InputInterface $input): HtmlCompareService
    {
        return new HtmlCompareService(
            new SnapshotRepository($this->stringOption($input, 'dir', self::DEFAULT_DIR)),
            new HtmlNormalizer($this->loadConfig($input)),
            new HtmlDiffer(max(1, $this->intOption($input, 'max-hunks', 40))),
        );
    }

    /**
     * Fichier de config de normalisation à utiliser : `--config` s'il est fourni,
     * sinon celui fourni avec le paquet. Permet à un agent de pointer vers la config
     * de SON projet (versionnée, modifiable) plutôt que celle, en lecture seule,
     * installée sous `vendor/`.
     */
    private function configPath(InputInterface $input): string
    {
        return $this->stringOption($input, 'config', \dirname(__DIR__, 2) . '/config/normalizer.json');
    }

    private function loadConfig(InputInterface $input): NormalizerConfig
    {
        return NormalizerConfig::fromJsonFile($this->configPath($input));
    }

    private function curlStrategy(InputInterface $input): CurlCaptureStrategy
    {
        $raw = $input->getOption('allow-host');
        $extra = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
        $hosts = array_merge(['localhost', '127.0.0.1'], $extra);

        // Session cookie read from the environment (never from a CLI argument,
        // which would be visible in `ps` / the shell history).
        $cookie = getenv('RENDERLOOP_COOKIE');
        $cookie = is_string($cookie) && $cookie !== '' ? $cookie : null;
        $cookieName = $this->optionalString($input, 'cookie-name') ?? 'PHPSESSID';

        return new CurlCaptureStrategy(
            new UrlGuard($hosts),
            $cookie,
            $cookieName,
            null,
            15,
            $this->optionalString($input, 'method'),
            $this->requestHeaders($input),
            $this->optionalString($input, 'data'),
        );
    }

    /**
     * En-têtes de la requête de capture : ceux passés via --header, auxquels
     * --ajax ajoute X-Requested-With (sans doublonner s'il est déjà présent).
     *
     * @return list<string>
     */
    private function requestHeaders(InputInterface $input): array
    {
        $raw = $input->getOption('header');
        $headers = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];

        if ($input->getOption('ajax') === true) {
            $alreadySet = false;
            foreach ($headers as $header) {
                if (stripos($header, 'x-requested-with:') === 0) {
                    $alreadySet = true;
                    break;
                }
            }
            if (!$alreadySet) {
                $headers[] = 'X-Requested-With: XMLHttpRequest';
            }
        }

        return $headers;
    }

    /**
     * Assertion de portée demandée par `--expect-selector`, ou null si l'option est
     * absente. Présente, elle fait du verdict de confinement la source du code de
     * retour (voir {@see renderDiff}).
     */
    private function scopeAssertion(InputInterface $input, string $before, string $after): ?ScopeAssertion
    {
        $selectors = $this->expectedSelectors($input);
        if ($selectors === []) {
            return null;
        }

        $checker = new ScopeAssertionChecker(
            $this->loadConfig($input),
            new HtmlDiffer(max(1, $this->intOption($input, 'max-hunks', 40))),
        );

        return $checker->check($before, $after, $selectors);
    }

    /**
     * @return list<string>
     */
    private function expectedSelectors(InputInterface $input): array
    {
        $raw = $input->getOption('expect-selector');

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    private function renderDiff(InputInterface $input, OutputInterface $output, DiffResult $diff, string $header, ?ScopeAssertion $assertion = null): int
    {
        // Avec une assertion de portée, le verdict de confinement EST le code de
        // retour (comme un assert PHPUnit) : 0 si confiné, non-zéro sinon. Sinon on
        // garde la sémantique git : --exit-code échoue sur un diff non vide, et sans
        // le flag le statut reste SUCCESS quel que soit le diff.
        if ($assertion !== null) {
            $diffStatus = $assertion->satisfied ? self::SUCCESS : self::FAILURE;
        } else {
            $diffStatus = ($input->getOption('exit-code') && !$diff->isIdentical())
                ? self::FAILURE
                : self::SUCCESS;
        }

        if ($this->isJson($input)) {
            $payload = ['summary' => $diff->summary(), 'diff' => $diff->unifiedDiff];
            if ($assertion !== null) {
                $payload['assertion'] = $assertion->toArray();
            }
            $output->writeln($this->json($payload));

            return $diffStatus;
        }

        if ($assertion !== null) {
            $this->writeAssertion($output, $assertion);
        }

        if ($diff->isIdentical()) {
            $output->writeln("<info>No difference</info> ({$header})");

            return $diffStatus;
        }

        $tags = implode(', ', $diff->tagsTouched);
        $output->writeln("<comment>{$header}</comment>");
        $output->writeln(sprintf(
            'Summary: +%d / -%d, %d hunk(s)%s, tags: %s',
            $diff->linesAdded,
            $diff->linesRemoved,
            $diff->hunks,
            $diff->truncated ? ' (truncated)' : '',
            $tags === '' ? '—' : $tags,
        ));
        $this->writeChanges($output, $diff->changes);
        $output->writeln('');
        $this->writeUnifiedDiff($output, $diff->unifiedDiff);

        return $diffStatus;
    }

    /**
     * Localized changes, before the raw diff: the semantic signal (which selector
     * changed, next to which label, from what to what) for a reader who should not
     * have to parse the unified diff. Empty when the change cascaded (see
     * {@see Change}) — the diff below stays the floor.
     *
     * @param list<Change> $changes
     */
    private function writeChanges(OutputInterface $output, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $output->writeln('Changes:');
        foreach ($changes as $change) {
            $where = $change->context !== null ? "{$change->selector} ({$change->context})" : $change->selector;
            $what = match ($change->kind) {
                Change::KIND_TEXT => sprintf('%s → %s', (string) $change->before, (string) $change->after),
                Change::KIND_ADDED => sprintf('+ %s', (string) $change->after),
                Change::KIND_REMOVED => sprintf('- %s', (string) $change->before),
                default => '',
            };
            $output->writeln(sprintf('  • %s: %s', $where, $what));
        }
    }

    /**
     * Verdict de l'assertion de portée, en tête du diff : vert si confiné, rouge
     * sinon — et dans ce cas on montre UNIQUEMENT ce qui a fuité hors de la portée
     * (les changements attendus, eux, sont masqués), pour que le lecteur voie le
     * problème sans le noyer dans le diff complet.
     */
    private function writeAssertion(OutputInterface $output, ScopeAssertion $assertion): void
    {
        $selectors = implode(', ', $assertion->expectedSelectors);

        if ($assertion->satisfied) {
            $output->writeln("<info>Assertion OK</info>: {$assertion->reason} ({$selectors}).");
        } else {
            $output->writeln("<error>Assertion FAILED</error>: {$assertion->reason} (expected only {$selectors}).");
        }

        if (!$assertion->insideScopeChanged) {
            $output->writeln('<comment>Note</comment>: no visible change inside the expected scope.');
        }

        if (!$assertion->satisfied && !$assertion->outOfScope->isIdentical()) {
            $output->writeln('Out of scope:');
            $this->writeChanges($output, $assertion->outOfScope->changes);
            $output->writeln('');
            $this->writeUnifiedDiff($output, $assertion->outOfScope->unifiedDiff);
        }

        $output->writeln('');
    }

    /**
     * The diff contains HTML (`<div>`…): we write it raw so the Symfony formatter
     * does not mistake it for style tags, and we colorize by hand in ANSI only if
     * the output supports it.
     */
    private function writeUnifiedDiff(OutputInterface $output, string $diff): void
    {
        $decorated = $output->isDecorated();
        foreach (explode("\n", $diff) as $line) {
            $rendered = $line;
            if ($decorated && $line !== '') {
                $rendered = match ($line[0]) {
                    '+' => "\033[32m{$line}\033[0m",
                    '-' => "\033[31m{$line}\033[0m",
                    '@' => "\033[36m{$line}\033[0m",
                    default => $line,
                };
            }
            $output->writeln($rendered, OutputInterface::OUTPUT_RAW);
        }
    }

    private function requireOption(InputInterface $input, string $name): string
    {
        $value = $this->optionalString($input, $name);
        if ($value === null) {
            throw new \InvalidArgumentException("Option --{$name} is required.");
        }

        return $value;
    }

    private function optionalString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : $default;
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $max - 1)) . '…';
    }

    private function isJson(InputInterface $input): bool
    {
        return $this->stringOption($input, 'format', 'table') === 'json';
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function fail(OutputInterface $output, string $message): int
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        $output->writeln("<error>{$message}</error>");

        return self::FAILURE;
    }
}
