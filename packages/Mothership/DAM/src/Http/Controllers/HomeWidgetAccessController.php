<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\User\Models\Role;

/**
 * Backs the superuser-only right-click menu on Home widgets: which custom roles may add a
 * given widget, toggled without leaving Home. Routes carry ManageFolderAccess, so everything
 * here is already superadmin-gated.
 *
 * Only custom roles are listed. HomeWidgetRegistry::roleWidgetIds() returns null for roles with
 * permission_type 'all', and RoleBuilder resets their stored list, so a toggle there is a no-op.
 */
class HomeWidgetAccessController extends Controller
{
    public function index(string $widget): JsonResponse
    {
        $definition = $this->definition($widget);

        $stored = DB::table('dam_role_settings')->pluck('home_widget_ids', 'role_id');

        $roles = Role::where('permission_type', 'custom')->orderBy('name')->get()
            ->map(fn ($role) => [
                'id'      => (int) $role->id,
                'name'    => $role->name,
                'enabled' => in_array($widget, $this->effectiveWidgetIds($stored[$role->id] ?? null), true),
            ])->values();

        return response()->json([
            'widget' => [
                'id'         => $widget,
                'title'      => $definition['title'] ?? $widget,
                'permission' => $definition['permission'] ?? null,
            ],
            'roles' => $roles,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $widget): JsonResponse
    {
        $definition = $this->definition($widget);

        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        // Cheap early exit; the authoritative check is repeated on the locked row inside the
        // transaction, because a role can be promoted to full access between the two.
        abort_if(
            Role::whereKey($data['role_id'])->value('permission_type') !== 'custom',
            422,
            'Only custom roles are gated by the Home widget list.'
        );

        DB::transaction(function () use ($data, $widget, $definition): void {
            // Both reads are locked and taken inside the transaction: two superusers toggling
            // different widgets for the same role would otherwise each read the pre-change list
            // and overwrite the other's edit with their own singleton. Locking the roles row is
            // also what serialises two concurrent first inserts, not the settings primary key.
            $role = Role::whereKey($data['role_id'])->lockForUpdate()->firstOrFail();

            abort_if(
                $role->permission_type !== 'custom',
                422,
                'Only custom roles are gated by the Home widget list.'
            );

            $row = DB::table('dam_role_settings')->where('role_id', $role->id)->lockForUpdate()->first();

            $ids = $this->effectiveWidgetIds($row->home_widget_ids ?? null);
            $ids = $data['enabled'] ? [...$ids, $widget] : array_diff($ids, [$widget]);

            // Same whitelist and ordering RoleBuilder applies, so a later save through the role
            // form produces an identical list rather than reshuffling it.
            $ids = array_values(array_intersect(array_keys(config('dam-home.widgets', [])), $ids));

            if ($row) {
                // Touch only what this endpoint owns; delete_own_only belongs to the role builder.
                DB::table('dam_role_settings')->where('role_id', $role->id)
                    ->update(['home_widget_ids' => json_encode($ids), 'updated_at' => now()]);
            } else {
                DB::table('dam_role_settings')->insert([
                    'role_id'         => $role->id,
                    'home_widget_ids' => json_encode($ids),
                    'delete_own_only' => false,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            // Enabling grants the permission the widget declares; without it the widget is listed
            // and still 403s inside HomeWidgetRegistry::allowed(). Disabling deliberately never
            // revokes it: dam.assets backs the role's entire DAM surface, far beyond one widget.
            $permission = $definition['permission'] ?? null;

            if ($data['enabled'] && $permission) {
                $permissions = $this->rolePermissions($role);

                if (! in_array($permission, $permissions, true)) {
                    $role->permissions = [...$permissions, $permission];
                    $role->save();
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    private function definition(string $widget): array
    {
        $definition = config('dam-home.widgets.'.$widget);

        abort_unless(is_array($definition), 422, 'Unknown widget.');
        abort_if((bool) ($definition['always_available'] ?? false), 422, 'Universal widgets cannot be role-gated.');

        return $definition;
    }

    /**
     * The widget ids a custom role may currently use, read through the gate's own decoder so the
     * two can never disagree.
     *
     * A null reading means the gate is skipped entirely — every widget is currently permitted —
     * which happens when the role has no dam_role_settings row at all, as roles created outside the
     * DAM role builder do. The gate gives such a role the default (non-paid) set, so seeding from that same
     * set keeps a first toggle from revoking every other widget and a disable from writing a deny-all empty list.
     *
     * Every other reading is taken verbatim from the registry, including the deny-all it gives an
     * explicit empty array, a JSON `null` literal, or a corrupt value. Returning the catalogue for
     * those would widen a role's access on the strength of a bad read.
     */
    private function effectiveWidgetIds(mixed $value): array
    {
        $decoded = HomeWidgetRegistry::decodeWidgetIds($value);

        return $decoded === null
            ? HomeWidgetRegistry::defaultWidgetIds()
            : array_values(array_map('strval', $decoded));
    }

    /** The column is json; tolerate both a cast array and a raw string. */
    private function rolePermissions(Role $role): array
    {
        $value = $role->permissions;
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
