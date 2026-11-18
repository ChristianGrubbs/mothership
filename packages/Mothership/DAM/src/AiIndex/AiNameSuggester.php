<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Support\RequestId;

/**
 * Rename with AI: `YYYY-MM-DD_<event-or-subject>_<place>` for one file, from its AI description, its tags and its
 * capture date, plus whatever NamingContext can add about the time and place. Nothing ships that answers the
 * context, so by default a name comes from the file alone. Proposes only; the Library's rename applies it.
 */
class AiNameSuggester
{
    private const DATE_KEYS = ['datetimeoriginal', 'createdate', 'mediacreatedate', 'datecreated'];

    public function __construct(
        private AiBridgeClient $bridge,
        private NamingContext $context,
    ) {}

    /** Null when the file has no AI description yet; it needs one first. */
    public function suggest(Asset $asset, ?AssetFile $file = null): ?array
    {
        $context = $file ? app(StackMemberNamingContext::class)->build(asset: $asset, file: $file) : null;
        $row = $context ? (object) ['caption' => $context['caption'], 'keywords' => json_encode($context['keywords']), 'visible_text' => $context['visible_text']] : DB::table('dam_ai_index')->where('asset_id', $asset->id)->first();

        if (! $row || ! filled($row->caption)) {
            return null;
        }

        $meta = $context['metadata'] ?? (is_array($asset->meta_data) ? $asset->meta_data : []);
        [$capturedAt, $dateSource] = $this->capturedAt(meta: $meta, asset: $asset);
        [$campus, $building] = $this->context->placeOf(metadata: $meta, assetId: $file ? 0 : (int) $asset->id);
        // The upload date says nothing about which event a photo shows.
        $events = $dateSource === 'exif' ? $this->context->eventsAround(capturedAt: $capturedAt) : [];
        $vocabulary = app(AiVocabulary::class)->enabledLabels();
        $tags = collect($context ? ($context['tags'] ?? []) : DB::table('dam_ai_tags')->where('asset_id', $asset->id)->whereIn('status', ['suggested', 'accepted'])->pluck('term'))
            ->map(fn ($term) => $vocabulary[$term] ?? null)->filter()->values()->all();

        $named = $this->bridge->name([
            'caption'      => (string) $row->caption,
            'keywords'     => array_values(array_filter(json_decode((string) ($row->keywords ?? '[]'), true) ?: [], 'is_string')),
            'tags'         => $tags,
            'visible_text' => (string) ($row->visible_text ?? ''),
            'file_name'    => (string) ($context['file_name'] ?? $asset->file_name),
            'folder_path'  => $context['folder_path'] ?? (string) ($asset->directories()->first()?->generatePath() ?? ''),
            'captured_at'  => $dateSource === 'exif' ? $capturedAt->format('Y-m-d H:i') : '',
            'campus'       => $campus,
            'building'     => $building,
            'events'       => $events,
        ]);

        $subject = self::slug(text: $named['subject'] !== '' ? $named['subject'] : Str::words((string) $row->caption, 6, ''), maxLength: 60);
        $placeText = $named['place'] !== '' ? $named['place'] : ($building !== '' ? $building : $campus);
        $place = self::slug(text: $placeText, maxLength: 32);

        if ($place !== '' && str_contains($subject, $place)) {
            $place = '';
        }

        $stem = implode('_', array_filter([$capturedAt->format('Y-m-d'), $subject !== '' ? $subject : 'photo', $place]));

        $this->log(event: 'rename.suggested', ctx: [
            'asset_id'      => (int) $asset->id, 'date_source' => $dateSource, 'events' => count($events),
            'attachment_id' => $file ? (int) $file->attachment_id : null, 'file_id' => $file ? (int) $file->id : null,
            'event_used'    => $named['event'] !== '', 'has_building' => $building !== '', 'has_place' => $place !== '',
        ]);

        return [
            'asset_id'      => (int) $asset->id,
            'attachment_id' => $file ? (int) $file->attachment_id : null,
            'file_id'       => $file ? (int) $file->id : null,
            'id'            => (int) $asset->id,
            'file_name'     => (string) ($context['file_name'] ?? $asset->file_name),
            'extension'     => $context['extension'] ?? pathinfo((string) $asset->file_name, PATHINFO_EXTENSION),
            'stem'          => $stem,
            'title'         => mb_substr($named['title'], 0, 160),
            'event'         => $named['event'],
            'captured_at'   => $capturedAt->format('Y-m-d H:i'),
            'date_source'   => $dateSource,
            'campus'        => $campus,
            'building'      => $building,
        ];
    }

    /** Lowercase ASCII words joined by hyphens, cut at a word boundary. */
    public static function slug(string $text, int $maxLength): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(Str::ascii($text))), '-');

        if (strlen($slug) <= $maxLength) {
            return $slug;
        }

        $cut = substr($slug, 0, $maxLength + 1);
        $boundary = strrpos($cut, '-');

        return rtrim($boundary !== false && $boundary > 0 ? substr($cut, 0, $boundary) : substr($slug, 0, $maxLength), '-');
    }

    /** @return array{0: CarbonImmutable, 1: 'exif'|'uploaded'} */
    private function capturedAt(array $meta, Asset $asset): array
    {
        $values = $this->flatten($meta);

        foreach (self::DATE_KEYS as $key) {
            $value = $values[$key] ?? null;

            if (is_string($value) && preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $m)
                && (int) $m[1] >= 1990 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                // Carbon rolls an out-of-range time into the next days, which would invent the name's date.
                && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) ($m[6] ?? 0) <= 59) {
                return [CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)), 'exif'];
            }
        }

        return [CarbonImmutable::parse($asset->created_at ?? now()), 'uploaded'];
    }

    /** Lowercased keys with any `Group:` prefix dropped, nested groups included; the first value wins. */
    private function flatten(array $meta): array
    {
        $flat = [];

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value);

                continue;
            }

            $flat[strtolower((string) preg_replace('/^.*:/', '', (string) $key))] ??= $value;
        }

        return $flat;
    }

    /** Off unless MOTHERSHIP_AI_RENAME_LOG is set; ids, counts and flags only, never names or captions. */
    private function log(string $event, array $ctx): void
    {
        if (! config('ai-index.rename_log')) {
            return;
        }

        $record = ['ts' => now()->toIso8601String(), 'run_id' => RequestId::current(), 'level' => 'debug',
            'event'     => $event, 'loc' => self::class, 'ctx' => $ctx];
        @file_put_contents(storage_path('logs/ai-rename.jsonl'), json_encode($record).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
