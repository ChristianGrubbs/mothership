<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;

/**
 * AI image-edit removal (operator decision 2026-09-21, scope "AI edit only").
 *
 * The manual Image Editor keeps both editor tables, so nothing is dropped. This purges only what the
 * removed feature leaves dead: `dam_image_editor_resources` rows of kind `draft` (queued, failed,
 * cancelled, unreviewed and accepted-but-never-saved generations) with their stored working files,
 * and the `dam.asset.editor.gpt` permission on custom roles.
 *
 * A saved document never names a draft row (saving converted or copied it to kind `saved`), but any
 * draft a document does name is kept and converted to `saved` the way a version save did, so no
 * owner-private `draft` row survives. Library assets and versions made from AI edits are ordinary
 * assets and are not touched.
 *
 * Irreversible. The rollback for data is the pre-deploy database dump.
 */
return new class extends Migration
{
    private const PERMISSION = 'dam.asset.editor.gpt';

    /** Rows commit in one explicit transaction below; storage is only touched after it commits. */
    public $withinTransaction = false;

    public function up(): void
    {
        $paths = [];
        $counts = ['drafts' => 0, 'kept_referenced' => 0, 'roles' => 0];

        DB::transaction(function () use (&$paths, &$counts): void {
            if (Schema::hasTable('dam_image_editor_resources')) {
                $this->purgeDrafts(paths: $paths, counts: $counts);
            }
            if (Schema::hasTable('roles')) {
                $this->revokePermission(counts: $counts);
            }
        });

        // After commit: a rolled-back purge must never leave rows pointing at deleted bytes. A failed
        // unlink is logged, not thrown: the rows are already gone, and a throw would only block boot.
        $disk = Storage::disk(Directory::getAssetDisk());
        $deleted = 0;
        $failures = [];
        foreach (array_unique($paths) as $path) {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path) ? $deleted++ : $failures[] = $path;
                }
            } catch (Throwable $e) {
                $failures[] = $path;
            }
        }

        $context = $counts + ['deleted_files' => $deleted, 'failed_files' => $failures];
        $failures ? Log::error('image_editor.ai_purge.storage_incomplete', $context) : Log::info('image_editor.ai_purge.completed', $context);
    }

    private function purgeDrafts(array &$paths, array &$counts): void
    {
        $referenced = [];
        if (Schema::hasTable('dam_image_editor_documents')) {
            foreach (DB::table('dam_image_editor_documents')->select(['original_base', 'document'])->cursor() as $record) {
                $document = json_decode((string) $record->document, true) ?: [];
                foreach ([$record->original_base, $document['base'] ?? null, $document['background']['image'] ?? null] as $id) {
                    if (is_string($id) && $id !== '') {
                        $referenced[$id] = true;
                    }
                }
            }
        }

        $purge = [];
        foreach (DB::table('dam_image_editor_resources')->where('kind', 'draft')->select(['id', 'path', 'details'])->cursor() as $draft) {
            if (isset($referenced[$draft->id])) {
                DB::table('dam_image_editor_resources')->where('id', $draft->id)->update(['kind' => 'saved', 'status' => 'saved']);
                $counts['kept_referenced']++;

                continue;
            }
            $purge[] = $draft->id;
            $details = json_decode((string) $draft->details, true) ?: [];
            foreach ([$draft->path, ...array_map(fn ($field) => $details[$field] ?? null, ['input', 'mask', 'reference', 'source', 'guidance'])] as $path) {
                // Only the editor's own working area; never a Library path.
                if (is_string($path) && str_starts_with($path, 'assets/.editor/') && ! str_contains($path, '..')) {
                    $paths[] = $path;
                }
            }
        }

        foreach (array_chunk($purge, 500) as $chunk) {
            $counts['drafts'] += DB::table('dam_image_editor_resources')->where('kind', 'draft')->whereIn('id', $chunk)->delete();
        }

        // A kept row may share a path with a purged one only through a bug; never delete bytes a row names.
        if ($paths) {
            $paths = array_values(array_diff($paths, DB::table('dam_image_editor_resources')->whereIn('path', array_unique($paths))->pluck('path')->all()));
        }
    }

    private function revokePermission(array &$counts): void
    {
        foreach (DB::table('roles')->whereNotNull('permissions')->select(['id', 'permissions'])->get() as $role) {
            $permissions = json_decode((string) $role->permissions, true);
            if (! is_array($permissions) || ! in_array(self::PERMISSION, $permissions, true)) {
                continue;
            }
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($permissions, [self::PERMISSION]))),
            ]);
            $counts['roles']++;
        }
    }

    public function down(): void
    {
        // Irreversible purge: restore the pre-deploy database dump instead.
    }
};
