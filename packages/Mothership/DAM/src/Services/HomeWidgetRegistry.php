<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;

class HomeWidgetRegistry
{
    public function catalog(): array
    {
        $allowedWidgetIds = $this->roleWidgetIds();
        $result = [];
        foreach (config('dam-home.widgets', []) as $id => $widget) {
            if (! self::configured($widget)) {
                continue;
            }
            $alwaysAvailable = (bool) ($widget['always_available'] ?? false);
            if (! $alwaysAvailable && $allowedWidgetIds !== null && ! in_array($id, $allowedWidgetIds, true)) {
                continue;
            }
            if (! $alwaysAvailable && isset($widget['permission']) && ! bouncer()->hasPermission($widget['permission'])) {
                continue;
            }
            $widget['id'] = $id;
            // A tool without an entry page (Signal: only the Home card and the ticker's files) has no frame to open.
            if ($widget['kind'] === 'tool' && isset($widget['entry'])) {
                $widget['url'] = route('admin.home.tool', ['widget' => $id, 'path' => $widget['entry']]);
            }
            unset($widget['entry'], $widget['permission'], $widget['requires_config']);
            $result[] = $widget;
        }

        return $result;
    }

    public function allowed(string $id): array
    {
        $widget = config('dam-home.widgets.'.$id);
        abort_unless(is_array($widget), 404);
        abort_unless($this->permits($id), 403);

        return $widget;
    }

    /**
     * The same decision as allowed(), without aborting: whether the signed-in user may use widget $id. The layout
     * asks it before it renders the global announcement strip, which is the RSS tool's, so a role without RSS
     * neither sees the strip nor requests its denied stylesheet and script.
     */
    public function permits(string $id): bool
    {
        $widget = config('dam-home.widgets.'.$id);
        if (! is_array($widget) || ! self::configured($widget)) {
            return false;
        }
        if ((bool) ($widget['always_available'] ?? false)) {
            return true;
        }
        $allowedWidgetIds = $this->roleWidgetIds();
        if ($allowedWidgetIds !== null && ! in_array($id, $allowedWidgetIds, true)) {
            return false;
        }

        return ! isset($widget['permission']) || bouncer()->hasPermission($widget['permission']);
    }

    /**
     * A widget with `requires_config` exists only while that config value is set. Video Downloader names the
     * Studio video service's address, so an install without the service shows no tile instead of a broken one.
     */
    private static function configured(array $widget): bool
    {
        return ! isset($widget['requires_config']) || filled(config($widget['requires_config']));
    }

    private function roleWidgetIds(): ?array
    {
        $roleId = auth('admin')->user()?->role_id;
        if (! $roleId) {
            return null;
        }

        $role = DB::table('roles')->where('id', $roleId)->first(['permission_type']);
        if ($role === null || $role->permission_type !== 'custom') {
            return null;
        }

        $value = DB::table('dam_role_settings')->where('role_id', $roleId)->value('home_widget_ids');

        // A custom role with no stored list (no settings row, or SQL NULL) is not "everything": paid tools are
        // opt-in, so it gets the default set. Roles saved as `all` never reach this and stay ungated above.
        return self::decodeWidgetIds($value) ?? self::defaultWidgetIds();
    }

    /**
     * The widgets a custom role has until an admin chooses its list: every Home tool except the paid ones
     * (config `paid` flag). The role editor pre-ticks exactly this set and the gate falls back to it, so a new
     * role never gets StylePress, SphereSeam or Image Vectorization by default.
     */
    public static function defaultWidgetIds(): array
    {
        return array_values(array_keys(array_filter(
            config('dam-home.widgets', []),
            fn (array $widget): bool => ! ($widget['paid'] ?? false)
        )));
    }

    /**
     * The one reading of a stored home_widget_ids value, shared with the widget-access editor so the
     * gate and the editor can never disagree about what a row means.
     *
     * SQL NULL (or no row at all) returns null, meaning "no list stored"; roleWidgetIds() turns that into
     * defaultWidgetIds() for a custom role, and a role saved as `all` is never gated. A JSON `null` literal and any value that fails
     * to parse both yield an empty list, i.e. no widgets; other JSON is cast to an array, so a scalar
     * becomes a one-element list and an object becomes its values. Treating those as the full
     * catalogue instead would silently widen a role's access on the strength of a bad read.
     */
    public static function decodeWidgetIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_values((array) json_decode(is_string($value) ? $value : json_encode($value), true));
    }
}
