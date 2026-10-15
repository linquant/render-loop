<?php

declare(strict_types=1);

/**
 * Framework-agnostic web example for linquant/render-loop.
 *
 * Run it with PHP's built-in server (the engine fetches pages over HTTP, so the
 * server must stay reachable):
 *
 *   php -S localhost:8000 -t examples/web
 *
 * then open http://localhost:8000/ and watch any page served by THIS server
 * (same origin — the SSRF guard only allows the local host).
 *
 * This file plays the role a `HtmlCompareController` would play inside a host
 * app, but with plain superglobals instead of a framework base controller — it
 * shows exactly which seams an integrator wires up.
 */

use RenderLoop\HtmlCompareException;
use RenderLoop\Model\SnapshotRepository;
use RenderLoop\Service\CurlCaptureStrategy;
use RenderLoop\Service\DiffResult;
use RenderLoop\Service\GitRevision;
use RenderLoop\Service\HtmlCompareService;
use RenderLoop\Service\HtmlDiffer;
use RenderLoop\Service\HtmlNormalizer;
use RenderLoop\Service\NormalizerConfig;
use RenderLoop\Service\UrlGuard;

require __DIR__ . '/../../vendor/autoload.php';

// --- Configuration (an integrator would read these from their app) ----------
$snapshotDir = sys_get_temp_dir() . '/render-loop-example';
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost';
$allowedHosts = [$host, 'localhost', '127.0.0.1'];
$sessionCookieName = 'PHPSESSID';

$service = static function () use ($snapshotDir): HtmlCompareService {
    return new HtmlCompareService(
        new SnapshotRepository($snapshotDir),
        new HtmlNormalizer(NormalizerConfig::default()),
        new HtmlDiffer(),
    );
};

$capture = static function () use ($allowedHosts, $sessionCookieName): CurlCaptureStrategy {
    $cookie = is_string($_COOKIE[$sessionCookieName] ?? null) ? $_COOKIE[$sessionCookieName] : null;

    return new CurlCaptureStrategy(new UrlGuard($allowedHosts), $cookie, $sessionCookieName);
};

// --- JSON router -------------------------------------------------------------
$action = is_string($_GET['hc'] ?? null) ? $_GET['hc'] : null;

if ($action !== null) {
    header('Content-Type: application/json');
    try {
        echo json_encode(handle($action, $service(), $capture()), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (HtmlCompareException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Internal error: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

/**
 * @return array<string, mixed>
 */
function handle(string $action, HtmlCompareService $service, CurlCaptureStrategy $capture): array
{
    switch ($action) {
        case 'capture':
            $snap = $service->capture(bodyStr('url'), $capture, optBody('tag'), GitRevision::current());

            return ['success' => true, 'id' => $snap->id()];

        case 'recheck':
            $result = $service->recheck(bodyStr('url'), $capture, GitRevision::current());

            return ['success' => true, 'baseline' => $result->baseline->id(), 'current' => $result->current->id()]
                + diffPayload($result->diff);

        case 'compare':
            return ['success' => true] + diffPayload($service->compareSnapshots(queryStr('from'), queryStr('to')));

        case 'api':
            $url = optQuery('url');
            $baselineId = $url !== null ? $service->latestBaseline($url)?->id() : null;
            $snapshots = array_map(static fn($s): array => [
                'id' => $s->id(),
                'url' => $s->url,
                'tag' => $s->tag,
                'datetime' => date('Y-m-d H:i:s', $s->timestamp),
                'is_baseline' => $s->id() === $baselineId,
            ], $service->listSnapshots($url));

            return ['success' => true, 'snapshots' => $snapshots, 'baseline' => $baselineId];

        case 'snapshot':
            $view = $service->viewSnapshot(queryStr('id'));

            return $view === null ? ['success' => false, 'message' => 'Snapshot not found.'] : ['success' => true] + $view;

        case 'delete':
            $deleted = $service->deleteSnapshot(bodyStr('id'));

            return ['success' => $deleted, 'message' => $deleted ? '' : 'Snapshot not found.'];

        case 'baseline':
            $service->setBaseline(bodyStr('url'), bodyStr('id'));

            return ['success' => true];

        default:
            return ['success' => false, 'message' => "Unknown action: {$action}"];
    }
}

/**
 * @return array{summary: array<string, mixed>, diff: string, identical: bool}
 */
function diffPayload(DiffResult $diff): array
{
    return ['summary' => $diff->summary(), 'diff' => $diff->unifiedDiff, 'identical' => $diff->isIdentical()];
}

function bodyStr(string $name): string
{
    $value = optBody($name);
    if ($value === null) {
        throw new HtmlCompareException("Missing parameter: {$name}");
    }

    return $value;
}

function optBody(string $name): ?string
{
    $value = $_POST[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

function queryStr(string $name): string
{
    $value = optQuery($name);
    if ($value === null) {
        throw new HtmlCompareException("Missing parameter: {$name}");
    }

    return $value;
}

function optQuery(string $name): ?string
{
    $value = $_GET[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

// --- Shell (no framework, just the page that boots the JS) -------------------
require __DIR__ . '/view.phtml';
