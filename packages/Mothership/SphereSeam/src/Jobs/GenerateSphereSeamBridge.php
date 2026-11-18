<?php

namespace Mothership\SphereSeam\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Mothership\DAM\Support\SharedHiggsfieldError;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use Mothership\SphereSeam\Services\SphereSeamHiggsfield;
use Throwable;

/**
 * Submits the blanked seam bridge to the shared subscription once, then polls it on the
 * StylePress queue the same way CompleteStylePressGeneration does: short worker runs,
 * released between polls, never holding a PHP worker through the whole generation.
 * A retry after submission only polls again; it never submits or charges twice.
 *
 * 2026-09-16: transient failures used to be terminal. Any throwable — an unreachable CLI,
 * a completed generation whose result URL had not landed yet — marked the row `failed`,
 * so a generation the work account had already been charged for was simply lost, and
 * retry() refused the row because it was no longer `failed` in a way it recognised.
 */
class GenerateSphereSeamBridge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    /**
     * Must stay below the stylepress worker's --timeout (80 s, compose.dev.yaml and
     * tools/studio/runtime.py). A job timeout above the worker's is never reached: the worker SIGKILLs the
     * attempt instead, so no catch block runs and the row keeps whatever status it held. Under
     * the worker's, the job's own alarm fires first and the failure is handled here.
     * SphereSeamHiggsfield::SUBMIT_BUDGET_SECONDS keeps the CLI calls inside this.
     */
    public int $timeout = 70;

    /** How long a submitted seam may stay unfinished before it is given up on. */
    public const BUDGET_MINUTES = 15;

    /** Delay between ordinary polls, in seconds. */
    private const POLL_DELAY = 15;

    /** Delay after a transient provider failure, in seconds. */
    private const TRANSIENT_DELAY = 30;

    public function __construct(public string $generationId)
    {
        // The stylepress worker consumes the *database* connection (compose: `queue:work database
        // --queue=stylepress`); the app default is redis, where nothing listens to this queue.
        $this->onConnection('database')->onQueue('stylepress');
    }

    public function handle(SphereSeamHiggsfield $provider): void
    {
        // SharedHiggsfield is a `scoped()` container binding (DAMServiceProvider) so that
        // HomeStylePressController's several separate app(SharedHiggsfield::class) call sites
        // share ONE deadline window per HTTP request. Laravel's queue worker already resets every
        // scoped binding between job attempts (including a released-and-redispatched attempt like
        // this job's own poll cycle) as part of its own lifecycle, the same way it resets them
        // between HTTP requests — that reset is relied on here rather than repeated by hand:
        // forcing it from inside handle() would also evict every OTHER scoped service (catalog,
        // permission, menu, AI helpers...) mid-job, ahead of queue middleware unwinding and any
        // post-job listeners, for no benefit this class needs.
        $generation = SphereSeamGeneration::find($this->generationId);
        if (! $generation || in_array($generation->status, ['completed', 'failed'], true)) {
            return;
        }

        try {
            if (! $generation->provider_request_id) {
                if (! $this->submit($generation, $provider)) {
                    return;
                }
            }

            $poll = $provider->poll($generation->provider_request_id);

            if ($poll['status'] === 'completed') {
                $generation->update(['status' => 'completed', 'bridge_result_url' => $poll['result_url'], 'finished_at' => now()]);

                return;
            }

            if (in_array($poll['status'], ['failed', 'nsfw', 'canceled', 'cancelled'], true)) {
                $this->fail($generation, 'Higgsfield reported the seam bridge as '.$poll['status'].'.', reason: 'provider_'.$poll['status']);

                return;
            }

            if ($this->budgetSpent($generation)) {
                $this->fail($generation, $this->budgetMessage(), reason: 'budget_exhausted');

                return;
            }

            $this->release(self::POLL_DELAY);
        } catch (SharedHiggsfieldError $error) {
            $this->handleProviderError($generation, $error);
        } catch (Throwable $exception) {
            // Anything not classified by the provider adapter is a defect on this side (a missing
            // bridge file, an unsupported parameter): retrying cannot help.
            $this->fail($generation, 'SphereSeam could not send this seam bridge for repair.', reason: 'unexpected', exception: $exception);
        }
    }

    /**
     * Submits exactly once. Returns false when the row has already been failed and this attempt
     * must stop.
     */
    private function submit(SphereSeamGeneration $generation, SphereSeamHiggsfield $provider): bool
    {
        // started_at is the submission claim, taken atomically before the CLI runs. A worker
        // killed between the claim and the id write leaves it set, so the conditional update
        // below fails on the next attempt and a generation the provider may already have charged
        // is never submitted a second time.
        $claimed = SphereSeamGeneration::whereKey($generation->id)
            ->whereNull('provider_request_id')
            ->whereNull('started_at')
            ->update(['started_at' => now(), 'error_message' => null]) === 1;

        if (! $claimed) {
            // Whatever attempt set this claim may have reached `generate create` before it died;
            // there is no way from here to know whether the provider charged it. A manual Retry
            // would submit a THIRD time on top of that uncertainty, so this is marked ambiguous
            // and retry() (and the UI's Retry button) must refuse it, not just this attempt.
            $this->fail(
                $generation,
                'A previous attempt already sent this seam bridge to Higgsfield. Start a new seam rather than paying for a second generation.',
                reason: 'submit_already_claimed',
                submissionAmbiguous: true,
            );

            return false;
        }

        try {
            $requestId = $provider->submit($generation);
        } catch (SharedHiggsfieldError $error) {
            if ($error->operation === 'generate.create') {
                // The CLI was already talking to the provider, so the generation may exist and may
                // be charged. Never retried automatically, whatever the classification says — and
                // never manually either: submissionAmbiguous persists that past this attempt.
                $this->fail($generation, 'Higgsfield did not confirm this seam bridge. Check the shared work account before starting another, in case it was charged.', reason: $error->reason, error: $error, submissionAmbiguous: true);

                return false;
            }

            // The account checks failed before `generate create` ran, so nothing was charged:
            // release the claim so a re-dispatch can submit once the connection is back.
            SphereSeamGeneration::whereKey($generation->id)->whereNull('provider_request_id')->update(['started_at' => null]);

            throw $error;
        }

        // The id and the status flip land in one statement, so the row is never `generating`
        // without the id a poll or a retry needs.
        SphereSeamGeneration::whereKey($generation->id)->update(['status' => 'generating', 'provider_request_id' => $requestId]);
        $generation->refresh();

        return true;
    }

    private function handleProviderError(SphereSeamGeneration $generation, SharedHiggsfieldError $error): void
    {
        if (! $error->retryable) {
            $this->fail($generation, 'Higgsfield could not produce this seam bridge.', reason: $error->reason, error: $error);

            return;
        }

        if ($this->budgetSpent($generation)) {
            $this->fail($generation, $this->budgetMessage(), reason: $error->reason.'+budget_exhausted', error: $error);

            return;
        }

        // An unreachable CLI, or a completed generation whose URL has not landed yet, is not a
        // lost generation. Keep the row `generating` and poll again inside the budget.
        Log::warning('SphereSeam provider call is temporarily unavailable.', [
            'event'  => 'sphereseam.provider.transient',
            'run_id' => $generation->id,
            'ctx'    => [
                'generation_id'       => $generation->id,
                'provider_request_id' => $generation->provider_request_id,
                'operation'           => $error->operation,
                'reason'              => $error->reason,
                'exit_code'           => $error->exitCode,
                'stderr_excerpt'      => $error->stderrExcerpt,
            ],
        ]);

        $this->release(self::TRANSIENT_DELAY);
    }

    private function fail(SphereSeamGeneration $generation, string $message, string $reason, ?SharedHiggsfieldError $error = null, ?Throwable $exception = null, bool $submissionAmbiguous = false): void
    {
        // The diagnosis lives here, not in error_message: the operator-facing text stays generic,
        // and the prompt and anything token-shaped are already stripped from the excerpt.
        Log::error('SphereSeam provider generation failed.', [
            'event'  => 'sphereseam.provider.failed',
            'run_id' => $generation->id,
            'ctx'    => [
                'generation_id'         => $generation->id,
                'provider_request_id'   => $generation->provider_request_id,
                'operation'             => $error?->operation,
                'reason'                => $reason,
                'exit_code'             => $error?->exitCode,
                'stderr_excerpt'        => $error?->stderrExcerpt ?? '',
                'error_type'            => $exception ? $exception::class : ($error ? $error::class : null),
                'model'                 => $generation->model,
                'resolution'            => $generation->resolution,
                'submission_ambiguous'  => $submissionAmbiguous,
            ],
        ]);

        // submission_ambiguous is permanent once set: it means a `generate create` call may have
        // reached the provider and charged it with no id ever recorded to poll. retry() (and the
        // Retry button) must refuse this row forever, not just this attempt — a manual retry would
        // risk a second real charge stacked on an unresolved first one.
        $generation->update([
            'status'                => 'failed',
            'error_message'         => $message,
            'finished_at'           => now(),
            'submission_ambiguous'  => $generation->submission_ambiguous || $submissionAmbiguous,
        ]);
    }

    private function budgetSpent(SphereSeamGeneration $generation): bool
    {
        return $generation->started_at !== null && $generation->started_at->lt(now()->subMinutes(self::BUDGET_MINUTES));
    }

    private function budgetMessage(): string
    {
        return 'Higgsfield did not finish the seam bridge within '.self::BUDGET_MINUTES.' minutes.';
    }
}
