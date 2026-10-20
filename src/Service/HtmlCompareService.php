<?php

declare(strict_types=1);

namespace RenderLoop\Service;

use RenderLoop\HtmlCompareException;
use RenderLoop\Model\Snapshot;
use RenderLoop\Model\SnapshotRepository;

/**
 * Façade du moteur, appelée par la commande console comme par le contrôleur web :
 * capturer une page, comparer deux captures, recontrôler une page vis-à-vis de sa
 * dernière référence. La normalisation est appliquée au moment de comparer.
 */
final class HtmlCompareService
{
    private SnapshotRepository $repository;

    private HtmlNormalizer $normalizer;

    private HtmlDiffer $differ;

    /** Étiquette des captures produites par un recontrôle : elles ne servent jamais de référence. */
    public const RECHECK_TAG = 'recheck';

    public function __construct(
        SnapshotRepository $repository,
        HtmlNormalizer $normalizer,
        HtmlDiffer $differ
    ) {
        $this->repository = $repository;
        $this->normalizer = $normalizer;
        $this->differ = $differ;
    }

    public function capture(string $url, CaptureStrategy $strategy, ?string $tag = null, ?string $gitCommit = null): Snapshot
    {
        $snapshot = Snapshot::create($url, $strategy->capture($url), $tag, $gitCommit);
        $this->repository->save($snapshot);

        return $snapshot;
    }

    public function compareSnapshots(string $fromId, string $toId): DiffResult
    {
        $from = $this->repository->get($fromId);
        if ($from === null) {
            throw new HtmlCompareException("Snapshot de référence introuvable : {$fromId}");
        }
        $to = $this->repository->get($toId);
        if ($to === null) {
            throw new HtmlCompareException("Snapshot courant introuvable : {$toId}");
        }

        return $this->diffSnapshots($from, $to);
    }

    /**
     * Capture la page maintenant et la compare à sa dernière référence.
     *
     * La référence est la dernière capture NON issue d'un recontrôle : ainsi
     * recontrôler plusieurs fois de suite compare toujours à la même baseline
     * posée délibérément, au lieu de dériver vers la capture précédente.
     */
    public function recheck(string $url, CaptureStrategy $strategy, ?string $gitCommit = null): RecheckResult
    {
        $baseline = $this->latestBaseline($url);
        if ($baseline === null) {
            throw new HtmlCompareException("Aucune capture de référence pour {$url} : capturez-la d'abord.");
        }

        $current = $this->capture($url, $strategy, self::RECHECK_TAG, $gitCommit);

        return new RecheckResult($baseline, $current, $this->diffSnapshots($baseline, $current));
    }

    /**
     * Référence de la page : la capture choisie explicitement (★) si elle existe
     * encore, sinon la plus récente qui n'est pas un recontrôle.
     */
    public function latestBaseline(string $url): ?Snapshot
    {
        $explicitId = $this->repository->baselineId(UrlFolderNamer::toFolderName($url));
        if ($explicitId !== null) {
            $explicit = $this->repository->get($explicitId);
            if ($explicit !== null) {
                return $explicit;
            }
            // Pointeur orphelin (capture supprimée) : on retombe sur l'implicite.
        }

        foreach ($this->listSnapshots($url) as $snapshot) {
            if ($snapshot->tag !== self::RECHECK_TAG) {
                return $snapshot;
            }
        }

        return null;
    }

    public function getSnapshot(string $id): ?Snapshot
    {
        return $this->repository->get($id);
    }

    public function deleteSnapshot(string $id): bool
    {
        return $this->repository->delete($id);
    }

    public function setBaseline(string $url, string $id): void
    {
        if ($this->repository->get($id) === null) {
            throw new HtmlCompareException("Capture introuvable : {$id}");
        }
        $this->repository->setBaseline(UrlFolderNamer::toFolderName($url), $id);
    }

    /**
     * Métadonnées + HTML normalisé d'une capture, pour l'inspecter dans l'UI.
     *
     * @return array{id: string, url: string, tag: ?string, datetime: string, html: string}|null
     */
    public function viewSnapshot(string $id): ?array
    {
        $snapshot = $this->repository->get($id);
        if ($snapshot === null) {
            return null;
        }

        return [
            'id' => $snapshot->id(),
            'url' => $snapshot->url,
            'tag' => $snapshot->tag,
            'datetime' => date('Y-m-d H:i:s', $snapshot->timestamp),
            'html' => $this->normalizer->normalize($snapshot->html),
        ];
    }

    /**
     * @return list<Snapshot> triées du plus récent au plus ancien
     */
    public function listSnapshots(?string $url = null): array
    {
        return $this->repository->list($url !== null ? UrlFolderNamer::toFolderName($url) : null);
    }

    /**
     * Capture la page deux fois sans rien changer et déduit les sélecteurs de
     * bruit (contenu volatil non encore masqué) à ajouter à la config.
     *
     * @return list<string>
     */
    public function calibrate(string $url, CaptureStrategy $strategy, int $delayMicros = 0, int $samples = 2): array
    {
        // Au moins deux captures pour pouvoir les comparer ; davantage attrape du
        // bruit plus rare (une valeur qui ne dérive pas à chaque requête).
        $samples = max(2, $samples);

        $captures = [];
        for ($i = 0; $i < $samples; $i++) {
            if ($i > 0 && $delayMicros > 0) {
                // Espacer les captures : sinon un horodatage « now » identique à la
                // seconde près ne diffère pas et le bruit temporel passe inaperçu.
                usleep($delayMicros);
            }
            $captures[] = $strategy->capture($url);
        }

        $first = array_shift($captures);
        if ($first === null) {
            throw new \LogicException('Calibration requires at least two captures.');
        }

        return (new NoiseCalibrator($this->normalizer))->calibrate($first, ...$captures);
    }

    private function diffSnapshots(Snapshot $from, Snapshot $to): DiffResult
    {
        return $this->differ->diff(
            $this->normalizer->normalize($from->html),
            $this->normalizer->normalize($to->html),
        );
    }
}
