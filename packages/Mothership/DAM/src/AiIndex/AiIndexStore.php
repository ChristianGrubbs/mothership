<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Support\RequestId;
use Throwable;

class AiIndexStore
{
    /** A running row not touched for this long has lost its job: the job's own timeout is 300 s, plus margin. */
    public const RUNNING_STALE_SECONDS = 600;

    /** A pending row younger than this still has its job waiting: the longest retry backoff is 15 minutes. */
    public const PENDING_GRACE_MINUTES = 20;

    /** Where the queue cannot be asked, a pending row is given this long before its job is taken as lost (a backlog is not a loss). */
    public const PENDING_UNVERIFIABLE_HOURS = 6;

    public const LIVE_IDLE = 'idle';

    public const LIVE_QUEUED = 'queued';

    public const LIVE_RUNNING = 'running';

    public const LIVE_LOST = 'lost';

    /**
     * Re-renders the row's search words, and on PostgreSQL its search vector, from the row as it is. The row is locked
     * for both writes in one transaction, so what is written is built from the row it replaces and nobody's newer
     * words or vector can land between the two statements. A run passes its token (`$owner`): then nothing is written
     * unless it still owns the row, and no row is made; anyone else (an edit, AiReindexLexical, a run that has finished)
     * writes whatever the row is now.
     */
    public function refreshLexical(Asset $asset, ?string $owner = null): void
    {
        DB::transaction(function () use ($asset, $owner): void {
            // The row exists before it is read, and is read locked: the words and the vector are built from the row as it
            // is at that moment, never from a "no row" snapshot taken before someone else made and filled it. insertOrIgnore
            // loses quietly to a row that is already there. No run_token on a row made here: no job is queued for it.
            if ($owner === null) {
                DB::table('dam_ai_index')->insertOrIgnore(['asset_id' => $asset->id, 'status' => 'pending', 'http_request_id' => RequestId::current(), 'created_at' => now(), 'updated_at' => now()]);
            }

            $row = DB::table('dam_ai_index')->where('asset_id', $asset->id)
                ->when($owner !== null, fn ($query) => $query->where('run_token', $owner))
                ->lockForUpdate()->first();

            if (! $row) {
                return;
            }

            $fields = LexicalDocument::fields(asset: $asset, aiRow: $row);
            $text = implode("\n", $fields);

            DB::table('dam_ai_index')->where('asset_id', $asset->id)->update(['lexical_text' => $text] + ($owner === null ? ['updated_at' => now()] : []));

            if (DB::connection()->getDriverName() === 'pgsql') {
                $table = DB::connection()->getQueryGrammar()->wrapTable('dam_ai_index');
                DB::statement(
                    "UPDATE {$table} SET search_tsv = setweight(to_tsvector('english', unaccent(?)), 'A') || setweight(to_tsvector('english', unaccent(?)), 'B') || setweight(to_tsvector('english', unaccent(?)), 'C') || setweight(to_tsvector('english', unaccent(?)), 'D') WHERE asset_id = ?",
                    [$fields['a'], $fields['b'], $fields['c'], $fields['d'], $asset->id]
                );
            }
        });
    }

    /**
     * Where this row's run stands. `idle`: nothing is queued (a finished or failed row, or a pending one made only to hold
     * the words, which has no token). `queued` / `running`: a job owns it. `lost`: its job is gone.
     *
     * Age alone cannot tell a lost job from a long backlog, so a pending row is judged by the queue where the queue can
     * be asked (the database driver: is a job carrying its token still there?) and by a much larger bound where it cannot.
     * A running row is judged by the job's own timeout.
     */
    public static function liveness(object $row): string
    {
        if (! in_array($row->status ?? null, ['pending', 'running'], true)) {
            return self::LIVE_IDLE;
        }

        // The run's own clock (set when it is queued, started or retried), not updated_at, which a words refresh also moves.
        $age = time() - (int) strtotime((string) ($row->claimed_at ?? $row->updated_at ?? 'now'));

        if ($row->status === 'running') {
            return $age > self::RUNNING_STALE_SECONDS ? self::LIVE_LOST : self::LIVE_RUNNING;
        }

        if (($row->run_token ?? null) === null) {
            return self::LIVE_IDLE;
        }

        if ($age < self::PENDING_GRACE_MINUTES * 60) {
            return self::LIVE_QUEUED;
        }

        $queued = self::jobIsQueued((string) $row->run_token);

        return match (true) {
            $queued === true  => self::LIVE_QUEUED,
            $queued === false => self::LIVE_LOST,
            default           => $age > self::PENDING_UNVERIFIABLE_HOURS * 3600 ? self::LIVE_LOST : self::LIVE_QUEUED,
        };
    }

    /** Whether the queue still holds a job with this token; null when the queue cannot be asked. */
    private static function jobIsQueued(string $token): ?bool
    {
        $queue = config('queue.connections.ai-index');

        if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
            return null;
        }

        try {
            return DB::connection($queue['connection'] ?? null)->table($queue['table'] ?? 'jobs')
                ->where('queue', $queue['queue'] ?? 'ai-index')
                ->where('payload', 'like', '%'.$token.'%')
                ->exists();
        } catch (Throwable) {
            // The queue table cannot be read: not known, so the row is judged by age alone and nothing is re-queued on a guess.
            return null;
        }
    }

    /**
     * Takes the right to queue one index run for this file and returns its token, or null when someone else holds it.
     * Exactly one of any number of callers racing for it wins (the update names the state it read). A run queued or
     * underway is left to finish; one whose job is gone, a pending row that never had a job, a failed row and a finished
     * one are taken, with the spent tries cleared. Every dispatch of AiIndexAsset goes through here or stamp().
     *
     * `$retrySha` is a manual retry of a file the AI found no picture in: each (file, content) is allowed one, taken
     * through a unique row, so two requests (or A, B, A content) cannot spend it twice. The reservation and the claim
     * are one transaction: a claim that fails or throws gives the retry back, so it is never spent without a run queued.
     */
    public function claim(int $assetId, ?string $retrySha = null): ?string
    {
        return DB::transaction(function () use ($assetId, $retrySha): ?string {
            if ($retrySha !== null && DB::table('dam_ai_index_retries')->insertOrIgnore(['asset_id' => $assetId, 'content_sha256' => $retrySha, 'created_at' => now()]) !== 1) {
                return null;
            }

            $token = $this->take($assetId);

            if ($token === null && $retrySha !== null) {
                DB::table('dam_ai_index_retries')->where('asset_id', $assetId)->where('content_sha256', $retrySha)->delete();
            }

            return $token;
        });
    }

    /** Whether the one manual retry for this content has been used. */
    public function retrySpent(int $assetId, string $sha256): bool
    {
        return DB::table('dam_ai_index_retries')->where('asset_id', $assetId)->where('content_sha256', $sha256)->exists();
    }

    private function take(int $assetId): ?string
    {
        $token = Str::uuid()->toString();

        if (DB::table('dam_ai_index')->insertOrIgnore(['asset_id' => $assetId, 'status' => 'pending', 'run_token' => $token, 'claimed_at' => now(), 'http_request_id' => RequestId::current(), 'created_at' => now(), 'updated_at' => now()]) === 1) {
            return $token;
        }

        $row = DB::table('dam_ai_index')->where('asset_id', $assetId)->first();

        if (! $row || in_array(self::liveness($row), [self::LIVE_QUEUED, self::LIVE_RUNNING], true)) {
            return null;
        }

        $taken = DB::table('dam_ai_index')->where('asset_id', $assetId)->where('status', $row->status)
            ->when($row->run_token === null, fn ($query) => $query->whereNull('run_token'), fn ($query) => $query->where('run_token', $row->run_token))
            ->update(['status' => 'pending', 'error' => null, 'attempts' => 0, 'run_token' => $token, 'claimed_at' => now(), 'updated_at' => now()]);

        return $taken === 1 ? $token : null;
    }

    /**
     * Gives the row a new token for a run about to be queued, which retires any job queued or running under the old one,
     * and returns it. A row with no description yet reads as pending until the run ends.
     */
    public function stamp(int $assetId): string
    {
        $token = Str::uuid()->toString();

        if (DB::table('dam_ai_index')->insertOrIgnore(['asset_id' => $assetId, 'status' => 'pending', 'run_token' => $token, 'claimed_at' => now(), 'http_request_id' => RequestId::current(), 'created_at' => now(), 'updated_at' => now()]) === 0) {
            DB::table('dam_ai_index')->where('asset_id', $assetId)->update(['run_token' => $token, 'claimed_at' => now()]);
            DB::table('dam_ai_index')->where('asset_id', $assetId)->whereNull('caption')->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
        }

        return $token;
    }

    /**
     * Counts this try; the count spans dispatches (a replacement of the content resets it, see AiIndexDispatcher::contentReplaced).
     * One row serves every run for an asset, so each run restamps it with the id of the request that queued it.
     * False when the run does not own the row (a newer run took its token, or it has none): the job ends.
     */
    public function markRunning(int $assetId, string $token): bool
    {
        return DB::table('dam_ai_index')->where('asset_id', $assetId)->where('run_token', $token)
            ->update(['status' => 'running', 'attempts' => DB::raw('COALESCE(attempts, 0) + 1'), 'http_request_id' => RequestId::current(), 'claimed_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * A try that threw and will be tried again after its backoff: the row waits as pending, not as running. `$error` is
     * why it threw when the AI service did not answer, so the card can say so while it waits. Only for the run that owns it.
     */
    public function markRetrying(int $assetId, string $token, ?string $error = null): void
    {
        DB::table('dam_ai_index')->where('asset_id', $assetId)->where('run_token', $token)->where('status', 'running')
            ->update(['status' => 'pending', 'error' => $error, 'claimed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Drops what the AI said about the previous content: caption, keywords, open suggestions and PDF page text. Accepted
     * and dismissed decisions stay. Only for the run that owns the row, checked under lock in the same transaction as
     * the deletes, so a run a newer one has replaced cannot wipe the newer one's description. False when it does not own it.
     */
    public function clearStaleAi(int $assetId, string $owner): bool
    {
        return DB::transaction(function () use ($assetId, $owner): bool {
            if (! DB::table('dam_ai_index')->where('asset_id', $assetId)->where('run_token', $owner)->lockForUpdate()->exists()) {
                return false;
            }

            $this->wipeAi($assetId);

            return true;
        });
    }

    private function wipeAi(int $assetId): void
    {
        DB::table('dam_ai_index')->where('asset_id', $assetId)->update([
            'caption' => null, 'visible_text' => null, 'keywords' => null, 'caption_model' => null, 'prompt_version' => null,
        ]);
        DB::table('dam_ai_tags')->where('asset_id', $assetId)->where('status', 'suggested')->delete();
        DB::table('dam_ai_vectors')->where('asset_id', $assetId)->where('kind', 'pdf_page')->delete();
    }

    public function contentSha(int $assetId): ?string
    {
        return DB::table('dam_ai_index')->where('asset_id', $assetId)->value('content_sha256');
    }

    /** Ends the run as failed and gives up its token. A run that does not own the row changes nothing. */
    public function markFailed(int $assetId, string $error, string $token): bool
    {
        return DB::table('dam_ai_index')->where('asset_id', $assetId)->where('run_token', $token)
            ->update(['status' => 'failed', 'error' => mb_substr($error, 0, 120), 'run_token' => null, 'updated_at' => now()]) === 1;
    }

    public static function summaryText(string $lexical, ?array $caption): string
    {
        $parts = $caption ? [$caption['caption'], implode(', ', $caption['keywords']), $caption['visible_text']] : [];

        return mb_substr(trim(implode("\n", [...$parts, $lexical])), 0, 4000);
    }

    /**
     * @param  string|null  $note  AiEligibility::NO_IMAGE when the run had no picture to caption
     * @param  array{caption: string, tags: list<string>, keywords: list<string>, visible_text: string, model: string, prompt_version: string}|null  $caption
     * @param  list<array{kind: string, page: ?int, vector: list<float>, text: string}>  $vectors
     * @return bool false when the run no longer owns the row (a newer run took its token): nothing is written
     */
    public function storeResult(int $assetId, ?string $sha256, ?array $caption, array $vectors, string $embedModel, ?string $note, string $token): bool
    {
        return DB::transaction(function () use ($assetId, $sha256, $caption, $vectors, $embedModel, $note, $token): bool {
            $current = DB::table('dam_ai_index')->where('asset_id', $assetId)->lockForUpdate()->first(['content_sha256', 'run_token']);

            if (! $current || $current->run_token !== $token) {
                return false;
            }

            $previousSha = $current->content_sha256;
            $row = [
                'status'         => 'done',
                'run_token'      => null,
                // `no_image`: the run found no picture to describe, so a `done` row with no caption says why.
                'error'          => $note,
                'content_sha256' => $sha256,
                'embed_model'    => $embedModel,
                'indexed_at'     => now(),
                'updated_at'     => now(),
            ];

            // A run that found nothing to look at keeps its count of tries; any other end clears it.
            if (! in_array($note, [AiEligibility::NO_IMAGE, AiEligibility::UNSUPPORTED], true)) {
                $row['attempts'] = 0;
            }

            if ($caption !== null) {
                $row += [
                    'caption'        => $caption['caption'],
                    'visible_text'   => $caption['visible_text'],
                    'keywords'       => json_encode($caption['keywords']),
                    'caption_model'  => $caption['model'],
                    'prompt_version' => $caption['prompt_version'],
                ];
            } elseif ($previousSha === null || $previousSha !== $sha256) {
                // Replaced content that yielded nothing to look at must not keep describing the old file;
                // a null caption lets the nightly reconcile retry. Same content (a transient gap) keeps its
                // caption, keywords and suggestions.
                $this->wipeAi($assetId);
            }

            DB::table('dam_ai_index')->where('asset_id', $assetId)->update($row);

            if ($caption !== null) {
                // A person's accept or dismiss survives re-captioning; only open suggestions are replaced.
                DB::table('dam_ai_tags')->where('asset_id', $assetId)->where('status', 'suggested')
                    ->whereNotIn('term', $caption['tags'])->delete();
                $decided = DB::table('dam_ai_tags')->where('asset_id', $assetId)->pluck('term')->all();

                foreach (array_diff($caption['tags'], $decided) as $term) {
                    DB::table('dam_ai_tags')->insert(['asset_id' => $assetId, 'term' => $term, 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            DB::table('dam_ai_vectors')->where('asset_id', $assetId)->delete();

            foreach ($vectors as $vector) {
                DB::table('dam_ai_vectors')->insert([
                    'asset_id' => $assetId, 'kind' => $vector['kind'], 'page' => $vector['page'],
                    'vector'   => VectorCodec::encode($vector['vector']), 'text' => mb_substr($vector['text'], 0, 4000),
                    'model'    => $embedModel, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return true;
        });
    }

    /** @return list<array{term: string, label: string}> */
    public function suggestedTags(int $assetId): array
    {
        // P5c (D8): only enabled terms; a disabled term's open suggestions leave the asset page.
        $vocabulary = app(AiVocabulary::class)->enabledLabels();
        // A suggestion naming a tag a person removed from this file is not offered again (P4 D8).
        $removed = array_flip(AssetTagWriter::removedNames($assetId));

        return DB::table('dam_ai_tags')->where('asset_id', $assetId)->where('status', 'suggested')->orderBy('id')->pluck('term')
            ->filter(fn ($term) => isset($vocabulary[$term]) && ! isset($removed[mb_strtolower((string) $vocabulary[$term])]))
            ->map(fn ($term) => ['term' => $term, 'label' => $vocabulary[$term]])->values()->all();
    }

    public function infoFor(int $assetId, bool $canReview): ?array
    {
        $row = DB::table('dam_ai_index')->where('asset_id', $assetId)->first();

        if (! $row) {
            return null;
        }

        $asset = Asset::query()->find($assetId);

        return [
            'status'       => $row->status,
            'eligibility'  => $asset ? app(AiEligibility::class)->evaluate(asset: $asset, row: $row, reachable: app(AiBridgeClient::class)->health()) : null,
            'caption'      => $row->caption,
            'visible_text' => $row->visible_text,
            'keywords'     => json_decode((string) ($row->keywords ?? '[]'), true) ?: [],
            'tags'         => $this->suggestedTags($assetId),
            'can_review'   => $canReview,
        ];
    }
}
