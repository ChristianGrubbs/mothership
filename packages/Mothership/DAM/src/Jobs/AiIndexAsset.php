<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\AiSamplerUnavailable;
use Mothership\DAM\AiIndex\AiVocabulary;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Automation\AutomationTriggers;
use Throwable;

/** Lexical doc, caption + vocabulary tags, and vectors for one asset (spec §2). Bridge failures retry 60/300/900 s, then `failed`. */
class AiIndexAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * The run this job belongs to (AiIndexStore::claim / stamp). Declared with a default so a job queued before runs had
     * tokens, which has no such property in its payload, still unserializes: it owns nothing, and takes the file through
     * claim() or ends.
     */
    public ?string $token = null;

    /** Set when this job took the file itself (it came without a token); see handle(). */
    private bool $claimedHere = false;

    public function __construct(public int $assetId, ?string $token = null)
    {
        $this->token = $token;
        $this->onConnection('ai-index');
        $this->onQueue('ai-index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AiIndexStore $store, MediaSampler $sampler, AiBridgeClient $bridge, ?AiVocabulary $vocabulary = null): void
    {
        try {
            $this->run($store, $sampler, $bridge, $vocabulary ?? app(AiVocabulary::class));
        } catch (Throwable $error) {
            // The queue tries again after its backoff: until then the file waits, it is not being described.
            $reason = $error instanceof AiBridgeUnavailable ? class_basename($error) : null;

            if ($this->token !== null && $this->claimedHere) {
                // A job queued before tokens existed comes back with no token, so its next try could not tell its own
                // waiting row from another run's. The row is freed instead (failed, claimable) and the next try claims it.
                $store->markFailed(assetId: $this->assetId, error: $reason ?? class_basename($error), token: $this->token);
            } elseif ($this->token !== null) {
                $store->markRetrying(assetId: $this->assetId, token: $this->token, error: $reason);
            }

            throw $error;
        }
    }

    private function run(AiIndexStore $store, MediaSampler $sampler, AiBridgeClient $bridge, AiVocabulary $vocabulary): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset) {
            // In the trash (or gone): the run ends here, so a restored file does not wait on a job that will not come.
            if ($this->token !== null) {
                $store->markFailed(assetId: $this->assetId, error: 'AssetUnavailable', token: $this->token);
            }

            return;
        }

        // Nothing is written until this run owns the row. A job queued before runs had tokens takes the file through the
        // same claim as anyone else, or ends when a run holds it.
        $token = $this->token;

        if ($token === null) {
            $token = $store->claim($asset->id);
            $this->claimedHere = $token !== null;
        }

        if ($token === null) {
            return;
        }

        $this->token = $token;

        if (! $bridge->ready()) {
            // Not a run that is waiting: say so, and the nightly reconcile queues it once the service is configured.
            $store->refreshLexical($asset, $token);
            $store->markFailed(assetId: $asset->id, error: class_basename(AiBridgeUnavailable::class), token: $token);

            return;
        }

        // A newer run for this file has taken the row (or it is gone): this one ends without touching it.
        if (! $store->markRunning(assetId: $asset->id, token: $token)) {
            return;
        }

        $store->refreshLexical($asset, $token);
        $storedSha = $store->contentSha($asset->id);
        $sample = $sampler->sample($asset);

        // A tool that is not installed, a timeout: the machine could not look, which says nothing about the file. Tried
        // again after the backoff, then failed with a reason that retries; never recorded as "no picture".
        if ($sample->images === [] && $sample->transient !== null) {
            throw new AiSamplerUnavailable('AI sampler could not run: '.$sample->transient.'.');
        }

        // The summary vector is built from the word index, so the previous content's caption, keywords and
        // PDF text must be gone before it is read, not after the result is stored.
        if ($storedSha !== null && $sample->sha256 !== null && $sample->sha256 !== $storedSha) {
            // Under the run's ownership: a newer run that took the file while this one was sampling keeps its description.
            if (! $store->clearStaleAi($asset->id, $token)) {
                return;
            }

            $store->refreshLexical($asset, $token);
        }

        $lexical = (string) DB::table('dam_ai_index')->where('asset_id', $asset->id)->value('lexical_text');
        $folder = (string) ($asset->directories()->first()?->generatePath() ?? '');

        $caption = $sample->images !== [] ? $bridge->caption(
            images: $sample->images,
            context: ['file_name' => (string) $asset->file_name, 'folder_path' => $folder, 'kind' => $sample->kind],
            vocabulary: $vocabulary->enabledTerms(),
        ) : null;

        if ($caption !== null) {
            // P5c (D8): a keyword that is an enabled term's synonym suggests that term too.
            $caption['tags'] = $vocabulary->resolve(tags: $caption['tags'], keywords: $caption['keywords']);
        }

        $texts = [AiIndexStore::summaryText(lexical: $lexical, caption: $caption)];
        $pageNumbers = [];

        foreach ($sample->pages as $index => $page) {
            if ($page !== '') {
                $texts[] = $page;
                $pageNumbers[] = $index + 1;
            }
        }

        $embedded = $bridge->embed(texts: $texts, kind: 'document');
        $vectors = [['kind' => 'summary', 'page' => null, 'vector' => $embedded[0], 'text' => $texts[0]]];

        foreach ($pageNumbers as $i => $pageNumber) {
            $vectors[] = ['kind' => 'pdf_page', 'page' => $pageNumber, 'vector' => $embedded[$i + 1], 'text' => $texts[$i + 1]];
        }

        // The asset can be deleted while the bridge works; its cascade already removed the index rows.
        if (! Asset::whereKey($asset->id)->exists()) {
            return;
        }

        // Nothing to look at is not a success to hide: the row says why. A file type the AI cannot read at all is
        // recorded as unsupported (final, and the card says so); one whose picture is still to come, or that held none, as no_image.
        $noImage = $sample->images === [];
        $source = $noImage ? AiEligibility::source($asset, $sample->sha256) : null;
        $note = $noImage ? ($source['state'] === AiEligibility::UNSUPPORTED ? AiEligibility::UNSUPPORTED : AiEligibility::NO_IMAGE) : null;
        $stored = $store->storeResult(assetId: $asset->id, sha256: $sample->sha256, caption: $caption, vectors: $vectors, embedModel: $bridge->embedModel(), note: $note, token: $token);

        // Superseded while the bridge worked: the newer run's result is the one that counts.
        if (! $stored) {
            return;
        }

        // A picture that is still to come (a DNG preview never made, a Blender or Unity poster that is gone) is asked
        // for now; when it lands, AiIndexDispatcher::derivativeReady runs this job again once.
        if ($source !== null) {
            match ($source['state'] === AiEligibility::AWAITING_PREVIEW ? $source['reason'] : null) {
                'dng_preview'                           => GenerateDngPreview::dispatch($asset->id),
                'embedded_thumbnail', 'poster_missing'  => GenerateGameAssetThumbnail::dispatch($asset->id),
                default                                 => null,
            };
        }

        if ($fresh = $asset->fresh()) {
            $store->refreshLexical($fresh);
        }

        // P5b (D19): an AI result is a rule trigger, keyed to the indexed content so a re-index of the same bytes
        // evaluates once. The index job itself still writes only dam_ai_* (roadmap §3.4).
        app(AutomationTriggers::class)->aiIndexed(assetId: (int) $asset->id, sha256: $sample->sha256);
    }

    public function failed(?Throwable $error): void
    {
        // A job that never owned a run has nothing to fail.
        if ($this->token !== null) {
            app(AiIndexStore::class)->markFailed(assetId: $this->assetId, error: $error ? class_basename($error) : 'failed', token: $this->token);
        }
    }
}
