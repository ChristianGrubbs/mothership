<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Video Downloader is for every signed-in account. A custom role whose Home tool list was saved before the tool
 * existed would not have it, so it is appended to each stored list once. A role with no stored list already gets it
 * (HomeWidgetRegistry::defaultWidgetIds()), and the full-access right-click menu on Home can still take it away
 * from a role afterwards.
 */
return new class extends Migration
{
    private const WIDGET = 'video-downloader';

    public function up(): void
    {
        foreach (DB::table('dam_role_settings')->whereNotNull('home_widget_ids')->get(['role_id', 'home_widget_ids']) as $row) {
            $ids = self::withWidget($row->home_widget_ids);
            if ($ids !== null) {
                DB::table('dam_role_settings')->where('role_id', $row->role_id)
                    ->update(['home_widget_ids' => json_encode($ids), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // No-op: removing the id on rollback would also undo a role an administrator gave the tool to on purpose.
    }

    /**
     * The stored list with the tool appended, or null when the row should be left alone: it already has the tool,
     * or it is not a JSON list (a value this migration does not understand is never rewritten).
     */
    public static function withWidget(mixed $stored): ?array
    {
        $ids = json_decode(is_string($stored) ? $stored : json_encode($stored), true);
        if (! is_array($ids) || ! array_is_list($ids) || in_array(self::WIDGET, $ids, true)) {
            return null;
        }

        return [...$ids, self::WIDGET];
    }
};
