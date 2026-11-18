<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Support\TagLog;
use Throwable;

/**
 * The AI vocabulary (P5c, D8): the terms the AI bridge may tag a file with, now a table an admin edits
 * (dam_ai_vocabulary) and seeded from config('ai-vocabulary.terms'), which stays the fallback while the table is
 * empty or missing.
 *
 * - The bridge is sent the enabled terms only, and its answer is intersected with them (AiBridgeClient), so a
 *   disabled term is never proposed again. A word the bridge answers with that is an enabled term's synonym counts
 *   as that term.
 * - Open suggestions of a disabled term leave the review queue, the asset page and search; tags people accepted stay.
 * - Accepting a term applies the tag it maps to when it maps to one, else the shared tag named by its label.
 * - A label is a tag name: TagNameRule applies.
 * - At most MAX_ENABLED terms may be enabled: the bridge refuses a vocabulary of more than 200
 *   (tools/studio/ai-service/server.py, validate_caption), and none at all.
 */
class AiVocabulary
{
    public const MAX_ENABLED = 200;

    public const MAX_SYNONYMS = 10;

    /** As dam_ai_tags.term. */
    public const TERM_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /**
     * Bumped by every save, so a copy read before it (another request's controller, a long-lived queue worker) reads
     * the table again instead of answering with the terms as they were.
     */
    public const VERSION_KEY = 'dam:ai-vocabulary:version';

    /** @var array<string, array{id: ?int, term: string, label: string, enabled: bool, synonyms: list<string>, maps_to_tag_id: ?int}>|null */
    private ?array $rows = null;

    private ?string $version = null;

    /**
     * Every term, enabled or not, in table order.
     *
     * @return array<string, array{id: ?int, term: string, label: string, enabled: bool, synonyms: list<string>, maps_to_tag_id: ?int}>
     */
    public function all(): array
    {
        $version = (string) Cache::get(self::VERSION_KEY, '0');

        if ($this->rows !== null && $this->version === $version) {
            return $this->rows;
        }

        $this->version = $version;

        $rows = [];

        try {
            $stored = Schema::hasTable('dam_ai_vocabulary') ? DB::table('dam_ai_vocabulary')->orderBy('id')->get() : collect();
        } catch (Throwable) {
            $stored = collect();
        }

        foreach ($stored as $row) {
            $rows[(string) $row->term] = [
                'id'             => (int) $row->id,
                'term'           => (string) $row->term,
                'label'          => (string) $row->label,
                'enabled'        => (bool) $row->enabled,
                'synonyms'       => array_values(array_filter((array) json_decode((string) ($row->synonyms ?? '[]'), true), 'is_string')),
                'maps_to_tag_id' => $row->maps_to_tag_id === null ? null : (int) $row->maps_to_tag_id,
            ];
        }

        if ($rows === []) {
            foreach ((array) config('ai-vocabulary.terms') as $term => $label) {
                $rows[(string) $term] = ['id' => null, 'term' => (string) $term, 'label' => (string) $label, 'enabled' => true, 'synonyms' => [], 'maps_to_tag_id' => null];
            }
        }

        return $this->rows = $rows;
    }

    /** @return array<string, array{id: ?int, term: string, label: string, enabled: bool, synonyms: list<string>, maps_to_tag_id: ?int}> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (array $row) => $row['enabled']);
    }

    /**
     * The enabled terms' keys, what the bridge is sent.
     *
     * @return list<string>
     */
    public function enabledTerms(): array
    {
        return array_keys($this->enabled());
    }

    /**
     * Term => label for the enabled terms.
     *
     * @return array<string, string>
     */
    public function enabledLabels(): array
    {
        return array_map(fn (array $row) => $row['label'], $this->enabled());
    }

    /** @return array{id: ?int, term: string, label: string, enabled: bool, synonyms: list<string>, maps_to_tag_id: ?int}|null */
    public function enabledTerm(string $term): ?array
    {
        $row = $this->all()[$term] ?? null;

        return $row !== null && $row['enabled'] ? $row : null;
    }

    /**
     * The enabled terms a bridge answer names: its tags that are enabled terms, then the enabled terms one of its
     * keywords is a synonym of (case-insensitively). A keyword that merely spells a term is not a suggestion.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $keywords
     * @return list<string>
     */
    public function resolve(array $tags, array $keywords = []): array
    {
        $enabled = $this->enabled();
        $bySynonym = [];

        foreach ($enabled as $term => $row) {
            foreach ($row['synonyms'] as $synonym) {
                $bySynonym[mb_strtolower(trim($synonym))] ??= $term;
            }
        }

        $terms = array_values(array_filter($tags, fn ($tag) => is_string($tag) && isset($enabled[$tag])));

        foreach ($keywords as $keyword) {
            if (is_string($keyword) && isset($bySynonym[mb_strtolower(trim($keyword))])) {
                $terms[] = $bySynonym[mb_strtolower(trim($keyword))];
            }
        }

        return array_values(array_unique($terms));
    }

    /** Forgets every copy of the terms, in this process and in others. */
    public function flush(): void
    {
        $this->rows = null;
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    /**
     * Adds or changes one term. `$term` is null to add one (its key comes from `$data['term']`).
     *
     * @param  array<string, mixed>  $data  term (new only), label, enabled, synonyms, maps_to_tag_id
     * @return array<string, mixed> the stored row
     */
    public function save(?string $term, array $data, ?int $actorId): array
    {
        $existing = $term === null ? null : DB::table('dam_ai_vocabulary')->where('term', $term)->first();
        abort_if($term !== null && $existing === null, 404);

        $key = $term ?? (string) ($data['term'] ?? '');
        $errors = [];

        if ($term === null) {
            if (preg_match(self::TERM_PATTERN, $key) !== 1) {
                $errors['term'][] = trans('dam::app.admin.ai-vocabulary.errors.term-format');
            } elseif (DB::table('dam_ai_vocabulary')->where('term', $key)->exists()) {
                $errors['term'][] = trans('dam::app.admin.ai-vocabulary.errors.term-taken');
            }
        }

        $label = array_key_exists('label', $data) ? (string) $data['label'] : (string) ($existing->label ?? '');

        if (($problem = TagNameRule::problem($label)) !== null) {
            $errors['label'][] = $problem;
        }

        $label = TagNameRule::normalize($label);
        $enabled = array_key_exists('enabled', $data) ? filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) : (bool) ($existing->enabled ?? true);
        $synonyms = array_key_exists('synonyms', $data) ? $this->synonyms($data['synonyms'], $errors) : json_decode((string) ($existing->synonyms ?? '[]'), true);
        $mapsTo = array_key_exists('maps_to_tag_id', $data) ? $data['maps_to_tag_id'] : ($existing->maps_to_tag_id ?? null);
        $mapsTo = $mapsTo === null || $mapsTo === '' ? null : (int) $mapsTo;

        if ($mapsTo !== null && ! Tag::query()->where('id', $mapsTo)->exists()) {
            $errors['maps_to_tag_id'][] = trans('dam::app.admin.ai-vocabulary.errors.tag-missing');
        }

        $enabledCount = DB::table('dam_ai_vocabulary')->where('enabled', true)->when($term !== null, fn ($query) => $query->where('term', '<>', $term))->count() + ($enabled ? 1 : 0);

        if ($enabled && $enabledCount > self::MAX_ENABLED) {
            $errors['enabled'][] = trans('dam::app.admin.ai-vocabulary.errors.too-many', ['max' => self::MAX_ENABLED]);
        }

        if (! $enabled && $enabledCount === 0) {
            $errors['enabled'][] = trans('dam::app.admin.ai-vocabulary.errors.last-enabled');
        }

        if ($errors !== []) {
            TagLog::write(event: 'vocabulary.refused', ctx: ['new' => $term === null, 'fields' => count($errors)]);

            throw ValidationException::withMessages($errors);
        }

        $values = ['label' => $label, 'enabled' => $enabled, 'synonyms' => $synonyms === [] ? null : json_encode(array_values($synonyms)), 'maps_to_tag_id' => $mapsTo, 'updated_at' => now()];

        if ($existing === null) {
            DB::table('dam_ai_vocabulary')->insert(['term' => $key, ...$values, 'created_at' => now()]);
        } else {
            DB::table('dam_ai_vocabulary')->where('id', $existing->id)->update($values);
        }

        $this->flush();

        // Search's band B carries the labels of open suggestions: a renamed, disabled or re-enabled term changes it.
        $searchChanged = $existing !== null && ($existing->label !== $label || (bool) $existing->enabled !== $enabled);
        $reindexed = $searchChanged ? $this->reindexSuggested($key) : 0;

        TagLog::write(event: $existing === null ? 'vocabulary.added' : 'vocabulary.changed', ctx: [
            'vocabulary_id' => (int) ($existing->id ?? DB::table('dam_ai_vocabulary')->where('term', $key)->value('id')),
            'enabled'       => $enabled,
            'renamed'       => $existing !== null && $existing->label !== $label,
            'synonyms'      => count($synonyms),
            'mapped'        => $mapsTo !== null,
            'reindexed'     => $reindexed,
            'actor_id'      => (int) $actorId,
        ]);

        return $this->present($key);
    }

    /** @return array<string, mixed> one term as the admin screen and REST show it */
    public function present(string $term): array
    {
        $row = $this->all()[$term];
        $tag = $row['maps_to_tag_id'] === null ? null : Tag::query()->where('id', $row['maps_to_tag_id'])->first(['id', 'name']);

        return [
            'id'       => $row['id'],
            'term'     => $row['term'],
            'label'    => $row['label'],
            'enabled'  => $row['enabled'],
            'synonyms' => $row['synonyms'],
            'maps_to'  => $tag === null ? null : ['id' => (int) $tag->id, 'name' => (string) $tag->name],
        ];
    }

    /**
     * Queues the lexical re-index of every asset with an open suggestion of `$term`, 200 ids per job.
     *
     * @return int assets queued
     */
    public function reindexSuggested(string $term): int
    {
        $count = 0;

        DB::table('dam_ai_tags')->where('term', $term)->where('status', 'suggested')->orderBy('id')->select(['id', 'asset_id'])
            ->chunkById(200, function ($rows) use (&$count): void {
                $ids = $rows->pluck('asset_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $count += count($ids);

                if (config('ai-index.index_enabled')) {
                    AiReindexLexical::dispatch($ids)->afterCommit();
                }
            });

        return $count;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function synonyms(mixed $value, array &$errors): array
    {
        $list = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];

        foreach ($list ?: [] as $synonym) {
            $synonym = TagNameRule::normalize((string) $synonym);

            if ($synonym === '') {
                continue;
            }

            if (TagNameRule::problem($synonym) !== null) {
                $errors['synonyms'][] = trans('dam::app.admin.ai-vocabulary.errors.synonym-refused');

                continue;
            }

            $clean[mb_strtolower($synonym)] ??= $synonym;
        }

        if (count($clean) > self::MAX_SYNONYMS) {
            $errors['synonyms'][] = trans('dam::app.admin.ai-vocabulary.errors.too-many-synonyms', ['max' => self::MAX_SYNONYMS]);
        }

        return array_values($clean);
    }
}
