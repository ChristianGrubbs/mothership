<?php

namespace Mothership\SphereSeam\Services;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use RuntimeException;

/**
 * SphereSeam's provider adapter over the shared StylePress subscription backend.
 *
 * The Mothership runs Higgsfield through the official CLI on the shared work account
 * (`SharedHiggsfield`), not through an API-key SDK worker. SphereSeam therefore reuses
 * that account, its mounted session, its queue and its telemetry; it never holds a
 * credential of its own. The blanked seam bridge is passed to the CLI as a local
 * reference image path, exactly like StylePress passes its uploaded references.
 */
class SphereSeamHiggsfield
{
    /**
     * CLI budget for SphereSeam's own submission, shorter than the shared default of 90 s.
     *
     * The stylepress worker runs with --timeout=80, and a submission is preceded by the two
     * account checks, each with its own budget. At the shared default a single submission could
     * outlive the worker, which then SIGKILLs the attempt: no catch block runs, the row keeps
     * whatever status it had, and the next attempt re-submits and charges again. Capping the
     * submission keeps the whole call inside GenerateSphereSeamBridge::$timeout.
     */
    public const SUBMIT_BUDGET_SECONDS = 25;

    public function __construct(protected SharedHiggsfield $shared) {}

    public function configured(): bool
    {
        return (bool) config('sphereseam.enabled') && $this->shared->enabled();
    }

    public function estimate(string $model, string $prompt, string $ratio, string $quality, string $resolution): array
    {
        $params = $this->providerParams(model: $model, ratio: $ratio, quality: $quality, resolution: $resolution);
        $estimate = $this->shared->estimate(prompt: $prompt, aspectRatio: $params['aspect_ratio'], quality: $params['quality'], resolution: $params['resolution'], references: [], model: $model, variant: $params['variant']);

        return ['credits' => $estimate['credits'] ?? null];
    }

    public function submit(SphereSeamGeneration $generation): string
    {
        $disk = Storage::disk($generation->storage_disk);
        if (! $disk->exists($generation->bridge_path)) {
            throw new RuntimeException('The locally prepared seam bridge is unavailable.');
        }
        $params = $this->providerParams(model: $generation->model, ratio: $generation->bridge_ratio, quality: $generation->quality, resolution: $generation->resolution);

        return $this->shared->submit(prompt: $generation->prompt, aspectRatio: $params['aspect_ratio'], quality: $params['quality'], resolution: $params['resolution'], references: [$disk->path($generation->bridge_path)], model: $generation->model, variant: $params['variant'], timeoutSeconds: self::SUBMIT_BUDGET_SECONDS);
    }

    /** @return array{status: string, result_url: ?string} */
    public function poll(string $providerRequestId): array
    {
        return $this->shared->poll(id: $providerRequestId);
    }

    /**
     * Provider parameters verified on 2026-09-10 against the paid proof runs.
     *
     * GPT Image 2.5 is asked for aspect_ratio "auto" so it follows the reference's own
     * framing instead of re-composing to a catalogue ratio. The "flare" variant kept the
     * locked strips within ~7-12 levels of the original. GPT Image 2 has no "auto" and no
     * variant. Quality and resolution come from the operator's choice or the Auto rule
     * resolved in the browser; the CLI accepts low/medium/high and 1k/2k/4k.
     */
    public function providerParams(string $model, string $ratio, string $quality, string $resolution): array
    {
        if (! in_array($model, (array) config('sphereseam.models'), true)) {
            throw new RuntimeException('Unsupported SphereSeam model.');
        }
        $params = ['aspect_ratio' => $ratio, 'quality' => $quality, 'resolution' => $resolution, 'variant' => null];
        if ($model === 'gpt_image_2_5') {
            $params['aspect_ratio'] = 'auto';
            $params['variant'] = (string) config('sphereseam.variant', 'flare');
        }

        return $params;
    }
}
