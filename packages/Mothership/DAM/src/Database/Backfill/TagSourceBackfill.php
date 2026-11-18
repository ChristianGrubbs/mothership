<?php

declare(strict_types=1);

namespace Mothership\DAM\Database\Backfill;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Support\TagLog;
use RuntimeException;

/**
 * Labels the `source` of existing dam_asset_tag rows (P4 D8). A row is `ai` when the asset has an accepted AI
 * suggestion whose vocabulary label names that tag (case-insensitively, as AiTagController matched it when it
 * attached the tag); every other row stays `manual`, the column default.
 *
 * Used by migration 2026_10_07_001002 and by `php artisan dam:tags:backfill-source [--dry-run]`. Query builder
 * only. Idempotent: it only ever turns `manual` into `ai`. The run reconciles: manual + ai after = rows before,
 * and the rows it changed = the `manual` rows it found to relabel. A real run throws on a mismatch and rolls back;
 * a dry run always rolls back.
 *
 * Known approximation: a tag a person added by hand before accepting the same AI suggestion is labelled `ai`; the
 * table has no timestamps that could tell the two apart.
 */
class TagSourceBackfill
{
    /** @return array<string, mixed> the reconciliation report */
    public function run(bool $dryRun = false): array
    {
        $report = [
            'dry_run'              => $dryRun,
            'rows_before'          => 0,
            'accepted_suggestions' => 0,
            'unmatched'            => 0,
            'to_relabel'           => 0,
            'relabelled'           => 0,
            'ai_after'             => 0,
            'manual_after'         => 0,
            'other_after'          => 0,
            'violations'           => [],
        ];

        DB::beginTransaction();

        try {
            $report['rows_before'] = DB::table('dam_asset_tag')->count();
            $vocabulary = (array) config('ai-vocabulary.terms');
            $pairs = [];

            DB::table('dam_ai_tags')->where('status', 'accepted')->orderBy('id')->select(['id', 'asset_id', 'term'])
                ->chunk(500, function ($rows) use ($vocabulary, &$pairs, &$report) {
                    $labels = [];

                    foreach ($rows as $row) {
                        $report['accepted_suggestions']++;
                        $label = $vocabulary[$row->term] ?? null;

                        if ($label === null) {
                            $report['unmatched']++;

                            continue;
                        }

                        $labels[mb_strtolower((string) $label)][] = (int) $row->asset_id;
                    }

                    $tagIds = DB::table('dam_tags')
                        ->whereIn(DB::raw('LOWER(name)'), array_map('strval', array_keys($labels)))
                        ->get(['id', 'name'])
                        ->mapWithKeys(fn ($tag) => [mb_strtolower((string) $tag->name) => (int) $tag->id]);

                    foreach ($labels as $label => $assetIds) {
                        $tagId = $tagIds[$label] ?? null;

                        foreach ($assetIds as $assetId) {
                            $tagId === null ? $report['unmatched']++ : $pairs[$assetId.':'.$tagId] = [$assetId, $tagId];
                        }
                    }
                });

            foreach (array_chunk(array_values($pairs), 200) as $chunk) {
                $matching = DB::table('dam_asset_tag')->where('source', 'manual')->where(function ($query) use ($chunk) {
                    foreach ($chunk as [$assetId, $tagId]) {
                        $query->orWhere(fn ($pair) => $pair->where('asset_id', $assetId)->where('tag_id', $tagId));
                    }
                });

                $report['to_relabel'] += (clone $matching)->count();
                $report['relabelled'] += $matching->update(['source' => 'ai']);
            }

            $counts = DB::table('dam_asset_tag')->select('source', DB::raw('COUNT(*) as aggregate'))->groupBy('source')->pluck('aggregate', 'source');
            $report['ai_after'] = (int) ($counts['ai'] ?? 0);
            $report['manual_after'] = (int) ($counts['manual'] ?? 0);
            $report['other_after'] = (int) $counts->except(['ai', 'manual'])->sum();

            if ($report['rows_before'] !== $report['ai_after'] + $report['manual_after'] + $report['other_after']) {
                $report['violations'][] = 'row count changed';
            }

            if ($report['relabelled'] !== $report['to_relabel']) {
                $report['violations'][] = 'relabelled rows differ from the rows found';
            }

            if ($dryRun || $report['violations'] !== []) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        TagLog::write(event: 'tag.source_backfill', ctx: array_diff_key($report, ['violations' => true]) + ['violation_count' => count($report['violations'])]);

        if (! $dryRun && $report['violations'] !== []) {
            throw new RuntimeException('Tag source backfill does not reconcile: '.implode('; ', $report['violations']));
        }

        return $report;
    }
}
