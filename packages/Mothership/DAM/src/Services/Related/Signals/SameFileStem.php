<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\SignalMatch;

/**
 * The same file name once case, extension, separators and copy or version suffixes are set aside: "Poster_v2.psd",
 * "poster (1).jpg" and "POSTER-final.png" share the stem "poster". The lookup is a case-insensitive LIKE on the
 * indexed file_name, the stem's words joined by single-character wildcards (so "_", "-", "." and " " all match),
 * capped at four times the per-signal limit and checked exactly in PHP.
 */
final class SameFileStem implements RelatedSignal
{
    private const MIN_STEM_LENGTH = 3;

    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        $stem = self::stem((string) $seed->file_name);

        if (mb_strlen($stem) < self::MIN_STEM_LENGTH) {
            return [];
        }

        $words = explode(' ', $stem);

        // Words are letters and digits only (stem() splits on everything else), so none carries a LIKE wildcard.
        if (array_filter($words, fn (string $word) => ! preg_match('/^[\p{L}\p{N}]+$/u', $word)) !== []) {
            return [];
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $rows = DB::table('dam_assets')
            ->where('file_name', $operator, implode('_', $words).'%')
            ->whereIn('id', $scope->visible())
            ->whereNotIn('id', $scope->exclude)
            ->orderByDesc('id')
            ->limit($scope->limit * 4)
            ->get(['id', 'file_name']);

        $matches = [];

        foreach ($rows as $row) {
            if (count($matches) < $scope->limit && self::stem((string) $row->file_name) === $stem) {
                $matches[(int) $row->id] = new SignalMatch(score: $weight);
            }
        }

        return $matches;
    }

    public function reason(SignalMatch $match): string
    {
        return trans('dam::app.admin.dam.asset.related.why.stem');
    }

    /**
     * The file name's stem: lower case, no extension, copy and version suffixes removed, separators as single spaces.
     *
     * TODO 2026-10-07: fold into Support/FilenameStem.php when P3b-2 lands it, so stacking suggestions and related
     * assets agree on what "the same name" means.
     */
    public static function stem(string $fileName): string
    {
        $stem = mb_strtolower(pathinfo($fileName, PATHINFO_FILENAME));
        $suffixes = [
            '/\s*\(\d+\)$/u',                                              // "poster (1)"
            '/[\s_.\-]*copy(\s*\d+)?$/u',                                   // "poster copy 2"
            '/[\s_.\-]*v\d+$/u',                                            // "poster_v3"
            '/[\s_.\-]*(final|edit|edited|draft|web|hires|lores|print)$/u', // "poster-final"
            '/@\d+x$/u',                                                    // "poster@2x"
            '/[\s_.\-]*\d+x\d+$/u',                                         // "poster-1920x1080"
        ];

        do {
            $before = $stem;
            $stem = (string) preg_replace($suffixes, '', $stem);
        } while ($stem !== $before);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $stem));
    }
}
