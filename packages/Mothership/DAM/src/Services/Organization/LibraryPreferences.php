<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Support\Facades\DB;

/**
 * P4 (D11): the Library sort each admin chose, kept server-side so it follows them across browsers. Only the general
 * sorts persist: `relevance` belongs to one search and `position` to one label, board or section, so neither becomes
 * anyone's standing default.
 */
class LibraryPreferences
{
    public const TABLE = 'dam_library_preferences';

    /** @return array<int, string> every sort that may be saved, as `column:direction` */
    public static function sorts(): array
    {
        $sorts = [];

        foreach (['created_at', 'name', 'size', 'updated_at'] as $column) {
            $sorts[] = $column.':asc';
            $sorts[] = $column.':desc';
        }

        if (PopularityScore::enabled()) {
            $sorts[] = LibraryOrganizationFilters::SORT_POPULARITY.':desc';
        }

        return $sorts;
    }

    public function sort(int $adminId): ?string
    {
        $sort = DB::table(self::TABLE)->where('admin_id', $adminId)->value('sort');

        // A saved popularity sort with the flag now off reads as no choice.
        return is_string($sort) && in_array($sort, self::sorts(), true) ? $sort : null;
    }

    public function setSort(int $adminId, ?string $sort): void
    {
        $now = now();
        $updated = DB::table(self::TABLE)->where('admin_id', $adminId)->update(['sort' => $sort, 'updated_at' => $now]);

        if ($updated === 0) {
            DB::table(self::TABLE)->insertOrIgnore(['admin_id' => $adminId, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now]);
            // A concurrent first save may have won the insert; the last write still wins.
            DB::table(self::TABLE)->where('admin_id', $adminId)->update(['sort' => $sort, 'updated_at' => $now]);
        }
    }
}
