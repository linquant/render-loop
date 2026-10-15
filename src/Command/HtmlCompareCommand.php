<?php

declare(strict_types=1);

namespace RenderLoop\Command;

use RenderLoop\Model\GoldenManifest;
use RenderLoop\Model\Snapshot;
use RenderLoop\Model\SnapshotRepository;
use RenderLoop\Service\CurlCaptureStrategy;
use RenderLoop\Service\DiffResult;
use RenderLoop\Service\GitRevision;
use RenderLoop\Service\GoldenMaster;
use RenderLoop\Service\HtmlCompareService;
use RenderLoop\Service\HtmlDiffer;
use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NormalizerConfig;
use RenderLoop\Service\PushedHtmlStrategy;
use RenderLoop\Service\UrlGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            ->addOption('action', 'a', InputOption::VALUE_REQUIRED, 'capture | store | recheck | compare | list | calibrate | golden-record | golden-verify', 'recheck')
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
            ->setHelp(<<<'HELP'
Detects the changes of a web page between two moments.

<info>Human workflow:</info>
  1. <comment>render-loop -a capture -u "http://localhost/page.php" -t before</comment>
  2. (you modify the page)
  3. <comment>render-loop -a recheck -u "http://localhost/page.php"</comment>

<info>JS/SPA pages</info> (the rendered HTML is pushed via STDIN by a headless browser):
  <comment>cat rendered.html | render-loop -a store -u "http://localhost/app" -t before</comment>

<info>Other actions:</info>
  compare --from=<id> --to=<id>   diff between two precise snapshots
  list [--url=...]                list snapshots
  calibrate --url=...             capture the page twice and suggest the noise selectors to mask

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

        return $this->renderDiff($input, $output, $result->diff, "{$result->baseline->id()} -> {$result->current->id()}");
    }

    private function doCompare(InputInterface $input, OutputInterface $output, HtmlCompareService $service): int
    {
        $from = $this->requireOption($input, 'from');
        $to = $this->requireOption($input, 'to');

        return $this->renderDiff($input, $output, $service->compareSnapshots($from, $to), "{$from} -> {$to}");
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
        $selectors = $service->calibrate($this->requireOption($input, 'url'), $this->curlStrategy($input), self::CALIBRATION_DELAY_MICROS);

        if ($this->isJson($input)) {
            $output->writeln($this->json(['candidates' => $selectors]));

            return self::SUCCESS;
        }

        if ($selectors === []) {
            $output->writeln('<info>No noise detected</info>: two identical captures, nothing to mask.');

            return self::SUCCESS;
        }

        $output->writeln('<comment>Candidate noise selectors</comment> (add them to config/normalizer.json):');
        foreach ($selectors as $selector) {
            $output->writeln('  - ' . $selector);
        }

        return self::SUCCESS;
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
            new HtmlNormalizer(NormalizerConfig::default()),
            new HtmlDiffer(max(1, $this->intOption($input, 'max-hunks', 40))),
        );
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

        return new CurlCaptureStrategy(new UrlGuard($hosts), $cookie, $cookieName);
    }

    private function renderDiff(InputInterface $input, OutputInterface $output, DiffResult $diff, string $header): int
    {
        // With --exit-code, a non-empty diff is a failure status (like `git diff
        // --exit-code`); an identical render is always a success. Without the flag
        // the status stays SUCCESS regardless, so existing callers are unaffected.
        $diffStatus = ($input->getOption('exit-code') && !$diff->isIdentical())
            ? self::FAILURE
            : self::SUCCESS;

        if ($this->isJson($input)) {
            $output->writeln($this->json(['summary' => $diff->summary(), 'diff' => $diff->unifiedDiff]));

            return $diffStatus;
        }

        if ($diff->isIdentical()) {
            $output->writeln("<info>No difference</info> ({$header})");

            return self::SUCCESS;
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
        $output->writeln('');
        $this->writeUnifiedDiff($output, $diff->unifiedDiff);

        return $diffStatus;
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
        $output->writeln("<error>{$message}</error>");

        return self::FAILURE;
    }
}
