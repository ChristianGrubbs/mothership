<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const CANONICAL_WIDGET = [
        'id'           => 'system-quickview',
        'size'         => 'wide',
        'columns'      => 4,
        'rows'         => 1,
        'grid_version' => 2,
        'settings'     => [],
    ];

    public function up(): void
    {
        DB::table('dam_home_layouts')
            ->orderBy('admin_id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::transaction(fn () => $this->migrateRow($row));
                }
            }, 'admin_id');
    }

    public function down(): void
    {
        // Deliberately no-op. Rewriting every saved layout on rollback would resurrect the
        // widget for admins who removed it on purpose after this migration ran; down() only
        // needs to undo schema, and this migration creates none.
    }

    /**
     * Decides the next widgets JSON for one stored layout, or null when the content is
     * malformed or not a widget list this migration understands. Exposed directly so Pest
     * can exercise every branch deterministically without a database.
     */
    public function appendIfValid(string $widgetsJson): ?string
    {
        try {
            // Associative decoding turns `{}` and `{"0":…}` into PHP lists, so the top-level type is
            // settled on the object-preserving decode before the widgets are read as arrays.
            if (! is_array(json_decode($widgetsJson, false, 512, JSON_THROW_ON_ERROR))) {
                return null;
            }

            $widgets = json_decode($widgetsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($widgets) || ! array_is_list($widgets)) {
            return null;
        }

        foreach ($widgets as $widget) {
            if (! is_array($widget) || ! isset($widget['id']) || ! is_string($widget['id'])) {
                return null;
            }
        }

        foreach ($widgets as $widget) {
            if ($widget['id'] === 'system-quickview') {
                return $widgetsJson;
            }
        }

        $widgets[] = self::CANONICAL_WIDGET;

        return json_encode($widgets, JSON_THROW_ON_ERROR);
    }

    private function migrateRow(object $row): void
    {
        $updated = $this->appendIfValid($row->widgets);

        if ($updated === null) {
            Log::warning('Skipped a dam_home_layouts row with malformed or unsupported widgets JSON while appending system-quickview.', [
                'admin_id' => $row->admin_id,
            ]);

            return;
        }

        if ($updated === $row->widgets) {
            // Either already contains system-quickview, or is otherwise unchanged.
            return;
        }

        $applied = DB::table('dam_home_layouts')
            ->where('admin_id', $row->admin_id)
            ->where('revision', $row->revision)
            ->update([
                'widgets'    => $updated,
                'revision'   => $row->revision + 1,
                'updated_at' => now(),
            ]);

        if ($applied === 0) {
            Log::warning('Could not append system-quickview to a dam_home_layouts row because its revision changed concurrently; left for operator review.', [
                'admin_id' => $row->admin_id,
            ]);
        }
    }
};
