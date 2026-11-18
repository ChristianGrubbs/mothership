<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\HomeWidgetRegistry;

/**
 * Stabilization 2026-10-12 (operator decision): a normal user can use nearly everything in the library, so every
 * "normal user" role gets the everyday keys, delete-own-only, guest upload links and the free Home tools, while the
 * paid tools (StylePress, SphereSeam, Image Vectorization) stay out.
 *
 * Target: custom roles that hold dam.asset.view and dam.asset.upload and not settings.roles (never by role name; a
 * role saved as `all` is never read from the permissions array and is left alone). Additive and idempotent: only
 * missing keys and tools are added, the paid tools (StylePress, SphereSeam, Image Vectorization) are taken out of the
 * tool list, only delete_own_only that is off is switched on, and a second run changes nothing.
 *
 * What each role actually received is recorded in dam_configuration under SNAPSHOT_KEY so down() reverts exactly
 * that, never a key or tool an admin granted by hand before or after. down() clears the snapshot.
 */
return new class extends Migration
{
    private const SNAPSHOT_KEY = 'DAM_USER_ROLE_EVERYDAY_SNAPSHOT';

    private const REQUIRED_KEYS = ['dam.asset.view', 'dam.asset.upload'];

    private const EXCLUDING_KEY = 'settings.roles';

    private const GRANTED_KEYS = [
        // Everyday library use.
        'dam.asset.files', 'dam.asset.files.index', 'dam.asset.files.store', 'dam.asset.files.destroy',
        'dam.asset.re_upload', 'dam.asset.editor', 'dam.asset.rename', 'dam.asset.moved',
        'dam.asset.download_compressed', 'dam.asset.embed', 'dam.asset.process', 'dam.asset.property.delete',
        'dam.asset.relationships', 'dam.asset.relationships.index', 'dam.asset.relationships.store', 'dam.asset.relationships.destroy',
        'dam.asset.comment', 'dam.asset.comment.index', 'dam.asset.comment.store', 'dam.asset.comment.update', 'dam.asset.comment.delete',
        'dam.asset.linked_resources', 'dam.asset.linked_resources.index',
        'dam.directory.rename', 'dam.directory.moved', 'dam.directory.download_zip', 'dam.directory.copy_structure',
        'dam.collections', 'dam.collections.create', 'dam.collections.update', 'dam.collections.delete',
        'dam.trash', 'dam.trash.index', 'dam.trash.restore',
        'dam.labels', 'dam.labels.assign', 'dam.template', 'dam.template.use',
        // Delete, only together with delete_own_only below.
        'dam.asset.destroy', 'dam.asset.mass_delete', 'dam.directory.destroy', 'dam.trash.delete',
        // Guest upload links.
        'dam.upload_requests', 'dam.upload_requests.index', 'dam.upload_requests.create', 'dam.upload_requests.edit', 'dam.upload_requests.review',
    ];

    /** Home tools these roles must have. */
    private const TOOLS_AVAILABLE = ['looper', 'rss', 'trending'];

    /** Home tools these roles must not have: the paid ones. Hidden, not revoked: the ACL key a role holds stays. */
    private const TOOLS_HIDDEN = ['stylepress', 'sphereseam', 'vectorization'];

    /** Columns this migration manages on a settings row; every other column is the administrators' and is never touched. */
    private const MANAGED_COLUMNS = ['role_id', 'home_widget_ids', 'delete_own_only', 'created_at', 'updated_at'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $snapshot = $this->readSnapshot();
            $changed = false;

            foreach ($this->targetRoles() as $role) {
                $entry = $this->grant(role: $role, previous: $snapshot[(string) $role->id] ?? null);

                if ($entry !== null) {
                    $snapshot[(string) $role->id] = $entry;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('dam_configuration')->updateOrInsert(
                    ['key' => self::SNAPSHOT_KEY],
                    ['value' => json_encode($snapshot, JSON_THROW_ON_ERROR)]
                );
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->readSnapshot() as $roleId => $entry) {
                $this->revert(roleId: (int) $roleId, entry: (array) $entry);
            }

            DB::table('dam_configuration')->where('key', self::SNAPSHOT_KEY)->delete();
        });
    }

    /** @return list<object> */
    private function targetRoles(): array
    {
        return DB::table('roles')
            ->where('permission_type', 'custom')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'permissions'])
            ->filter(function (object $role): bool {
                $permissions = $this->permissionsOf($role);

                return array_diff(self::REQUIRED_KEYS, $permissions) === []
                    && ! in_array(self::EXCLUDING_KEY, $permissions, true);
            })
            ->values()
            ->all();
    }

    /**
     * Applies the grant to one role and returns the snapshot entry to store, or null when the role already has
     * everything (a repeat run). A role touched by an earlier run keeps that run's recorded baseline.
     */
    private function grant(object $role, ?array $previous): ?array
    {
        $permissions = $this->permissionsOf($role);
        $missingKeys = array_values(array_diff(self::GRANTED_KEYS, $permissions));

        $row = DB::table('dam_role_settings')->where('role_id', $role->id)->lockForUpdate()->first();
        $storedWidgets = $row === null ? null : HomeWidgetRegistry::decodeWidgetIds($row->home_widget_ids);
        $ownOnlyBefore = $row !== null && (bool) $row->delete_own_only;

        $toolsAdded = [];
        $toolsRemoved = [];
        $widgetsWritten = null;
        $baseline = null;

        if ($storedWidgets === null) {
            // No list stored: the role has the default set (every free tool). Make that explicit, so the
            // available/hidden decision is a stored fact rather than a fallback.
            $widgetsWritten = HomeWidgetRegistry::defaultWidgetIds();
            $newWidgets = $widgetsWritten;
        } else {
            $toolsAdded = array_values(array_diff(self::TOOLS_AVAILABLE, $storedWidgets));
            $toolsRemoved = array_values(array_intersect(self::TOOLS_HIDDEN, $storedWidgets));
            $newWidgets = array_values(array_diff([...$storedWidgets, ...$toolsAdded], $toolsRemoved));
        }

        $widgetsChange = $storedWidgets === null || $toolsAdded !== [] || $toolsRemoved !== [];
        $ownOnlyChange = ! $ownOnlyBefore;

        if ($missingKeys === [] && ! $widgetsChange && ! $ownOnlyChange) {
            return null;
        }

        if ($missingKeys !== []) {
            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...$missingKeys]))),
            ]);
        }

        if ($widgetsChange || $ownOnlyChange) {
            $values = ['delete_own_only' => true, 'home_widget_ids' => json_encode($newWidgets), 'updated_at' => now()];

            if ($row === null) {
                DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'created_at' => now(), ...$values]);
                // What the row looked like when this migration made it, apart from what it manages: rollback deletes
                // the row only while nobody has edited anything else on it (folder access, inherit_children, ...).
                $baseline = array_diff_key((array) DB::table('dam_role_settings')->where('role_id', $role->id)->first(), array_flip(self::MANAGED_COLUMNS));
            } else {
                DB::table('dam_role_settings')->where('role_id', $role->id)->update($values);
            }
        }

        return [
            'keys'                  => array_values(array_unique([...(array) ($previous['keys'] ?? []), ...$missingKeys])),
            'tools_added'           => array_values(array_unique([...(array) ($previous['tools_added'] ?? []), ...$toolsAdded])),
            'tools_removed'         => array_values(array_unique([...(array) ($previous['tools_removed'] ?? []), ...$toolsRemoved])),
            'tools_written'         => $previous['tools_written'] ?? $widgetsWritten,
            'settings_row_created'  => $previous['settings_row_created'] ?? ($row === null),
            'settings_baseline'     => $previous['settings_baseline'] ?? $baseline,
            'delete_own_only_prior' => array_key_exists('delete_own_only_prior', (array) $previous)
                ? $previous['delete_own_only_prior']
                : ($ownOnlyChange ? $ownOnlyBefore : null),
        ];
    }

    private function revert(int $roleId, array $entry): void
    {
        $role = DB::table('roles')->where('id', $roleId)->lockForUpdate()->first(['id', 'permissions']);

        if ($role === null) {
            return;
        }

        $keys = (array) ($entry['keys'] ?? []);

        if ($keys !== []) {
            DB::table('roles')->where('id', $roleId)->update([
                'permissions' => json_encode(array_values(array_diff($this->permissionsOf($role), $keys))),
            ]);
        }

        $row = DB::table('dam_role_settings')->where('role_id', $roleId)->lockForUpdate()->first();

        if ($row === null) {
            return;
        }

        $values = [];
        $current = HomeWidgetRegistry::decodeWidgetIds($row->home_widget_ids);
        $written = $entry['tools_written'] ?? null;

        if (is_array($written)) {
            // The migration wrote the whole default list: put the "no list stored" state back if the list is
            // still exactly what was written, and leave an admin's later edit alone otherwise.
            if ($current !== null && $current === $written) {
                $values['home_widget_ids'] = null;
            }
        } elseif ($current !== null) {
            $restored = array_values(array_diff($current, (array) ($entry['tools_added'] ?? [])));
            $restored = array_values(array_unique([...$restored, ...(array) ($entry['tools_removed'] ?? [])]));

            if ($restored !== $current) {
                $values['home_widget_ids'] = json_encode($restored);
            }
        }

        $ownOnlyPrior = $entry['delete_own_only_prior'] ?? null;

        if ($ownOnlyPrior !== null && (bool) $row->delete_own_only) {
            $values['delete_own_only'] = (bool) $ownOnlyPrior;
        }

        if ($values !== []) {
            DB::table('dam_role_settings')->where('role_id', $roleId)->update([...$values, 'updated_at' => now()]);
        }

        if (($entry['settings_row_created'] ?? false) === true) {
            $after = DB::table('dam_role_settings')->where('role_id', $roleId)->first();

            $baseline = $entry['settings_baseline'] ?? null;

            // Delete only a row that is still exactly what was created: managed fields back to their defaults and
            // every other column equal to the creation baseline. Anything else is an administrator's edit and stays.
            if ($after !== null
                && is_array($baseline)
                && $after->home_widget_ids === null
                && ! (bool) $after->delete_own_only
                && $this->normalized(array_diff_key((array) $after, array_flip(self::MANAGED_COLUMNS))) === $this->normalized($baseline)) {
                DB::table('dam_role_settings')->where('role_id', $roleId)->delete();
            }
        }
    }

    /** Column values compared as strings, so a boolean 0 and false, or an id as int and string, are the same. */
    private function normalized(array $columns): array
    {
        ksort($columns);

        return array_map(fn ($value) => $value === null ? null : (string) (is_bool($value) ? (int) $value : $value), $columns);
    }

    private function readSnapshot(): array
    {
        $value = DB::table('dam_configuration')->where('key', self::SNAPSHOT_KEY)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function permissionsOf(object $role): array
    {
        $decoded = json_decode((string) ($role->permissions ?? '[]'), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
};
