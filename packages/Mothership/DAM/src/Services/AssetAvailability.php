<?php

namespace Mothership\DAM\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;

/**
 * P2 S3: reads and changes an asset's availability state and window, for the Properties panel and the REST
 * update alike. Changes save through the model, so History records them like any other asset field.
 */
class AssetAvailability
{
    public const FIELDS = ['availability', 'available_from', 'available_until'];

    /**
     * The range a window end may take. A year past 9999 (the panel's date input sends `+010000-…`) cannot be stored
     * as a datetime and used to answer 500; a year before 1970 stored with a historic zone offset.
     */
    public const EARLIEST = '1970-01-01';

    public const LATEST = '2100-01-01';

    /**
     * The fields the request sets, validated against the asset's current values: a window that ends where or
     * before it starts is refused whichever end the request changes. $partial: only the fields present (REST
     * update); otherwise the state is required and both ends are taken as sent (the panel saves all three).
     *
     * @return array<string, mixed>
     */
    public static function validate(Request $request, Asset $asset, bool $partial = false): array
    {
        $range = ['after_or_equal:'.self::EARLIEST, 'before:'.self::LATEST];
        $rangeMessage = trans('dam::app.admin.dam.asset.availability.window-range');

        $data = $request->validate([
            'availability'    => [$partial ? 'sometimes' : 'required', 'string', Rule::in(Asset::AVAILABILITY_STATES)],
            'available_from'  => [$partial ? 'sometimes' : 'present', 'nullable', 'date', ...$range],
            'available_until' => [$partial ? 'sometimes' : 'present', 'nullable', 'date', ...$range],
        ], [
            'available_from.after_or_equal'  => $rangeMessage,
            'available_from.before'          => $rangeMessage,
            'available_until.after_or_equal' => $rangeMessage,
            'available_until.before'         => $rangeMessage,
        ]);

        // An instant sent with an offset (the panel sends UTC) is stored as the app zone's wall time, the way the
        // datetime cast reads it back; stored as sent, it would move by the zone's offset.
        foreach (['available_from', 'available_until'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->setTimezone(config('app.timezone'));
            }
        }

        $from = array_key_exists('available_from', $data) ? $data['available_from'] : $asset->available_from;
        $until = array_key_exists('available_until', $data) ? $data['available_until'] : $asset->available_until;

        if ($from !== null && $until !== null && Carbon::parse($until)->lte(Carbon::parse($from))) {
            throw ValidationException::withMessages([
                'available_until' => trans('dam::app.admin.dam.asset.availability.window-order'),
            ]);
        }

        return $data;
    }

    /** @param array<string, mixed> $data validated by validate() */
    public function set(Asset $asset, array $data): array
    {
        // Who can see a file is data on it: a role limited to its own items sets it only on files it added.
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        $asset->update(array_intersect_key($data, array_flip(self::FIELDS)));

        return self::present($asset->refresh());
    }

    /** What the panel and badges show: the stored fields plus why the asset is not out for use now (or null). */
    public static function present(Asset $asset): array
    {
        return [
            'availability'    => $asset->availability ?? Asset::AVAILABILITY_AVAILABLE,
            'available_from'  => $asset->available_from?->toIso8601String(),
            'available_until' => $asset->available_until?->toIso8601String(),
            'unavailable'     => $asset->unavailableBecause(),
        ];
    }
}
