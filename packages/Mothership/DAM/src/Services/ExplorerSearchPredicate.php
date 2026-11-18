<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Support\SearchTerms;

/** The Library's word match: file name, stack key, display name, Campus property, tag names. Words and spellings: SearchTerms. */
final class ExplorerSearchPredicate
{
    public static function apply($query, string $search, string $likeOperator): void
    {
        // Raw SQL does not get the table prefix (DB_PREFIX) on its own: the grammar wraps the column, as displayNameSql() does.
        $grammar = DB::connection()->getQueryGrammar();
        $stackKey = $grammar->wrap('dam_assets.stack_key');
        $fileName = $grammar->wrap('dam_assets.file_name');
        $tagName = $grammar->wrap('dam_tags.name');
        $escape = " ESCAPE '".SearchTerms::ESCAPE."'";

        $query->where(function ($words) use ($search, $likeOperator, $stackKey, $fileName, $tagName, $escape) {
            SearchTerms::apply($words, $search, function ($w, string $pattern) use ($likeOperator, $stackKey, $fileName, $tagName, $escape) {
                $w->whereRaw("{$fileName} {$likeOperator} ?{$escape}", [$pattern])
                    ->orWhereRaw(SearchTerms::likeSql($stackKey), [$pattern])
                    ->orWhereRaw(SearchTerms::likeSql(Asset::displayNameSql()), [$pattern])
                    ->orWhereExists(DB::table('dam_asset_properties')
                        ->whereColumn('dam_asset_properties.dam_asset_id', 'dam_assets.id')
                        ->where('name', 'Campus')->whereRaw(SearchTerms::likeSql('value'), [$pattern])
                        ->select(DB::raw(1)))
                    ->orWhereExists(
                        DB::table('dam_tags')
                            ->join('dam_asset_tag', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                            ->whereColumn('dam_asset_tag.asset_id', 'dam_assets.id')
                            ->whereRaw("{$tagName} {$likeOperator} ?{$escape}", [$pattern])
                            ->select(DB::raw(1))
                    );
            });
        });
    }
}
