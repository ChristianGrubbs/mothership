<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CaptureGroup;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\Directory;

class CaptureGroupingService
{
    public function preview(CaptureProfile $profile, int $directoryId): array
    {
        $assets = Asset::query()->where('capture_grouping_disabled', false)
            ->whereHas('directories', fn ($q) => $q->where('dam_directories.id', $directoryId))
            ->with('directories')->orderBy('id')->get(['id', 'file_name', 'extension', 'path', 'updated_at', 'capture_group_id'])
            ->filter(fn ($a) => $a->directories->count() === 1 && in_array(strtolower($a->extension), $profile->extensions, true));
        $groups = [];
        foreach ($assets->groupBy(fn ($a) => pathinfo($a->file_name, PATHINFO_FILENAME)) as $stem => $members) {
            if ($members->count() < 2 || $members->pluck('extension')->map(fn ($e) => strtolower($e))->unique()->count() !== $members->count()) {
                continue;
            }
            $groupIds = $members->pluck('capture_group_id')->filter()->unique();
            if ($groupIds->count() > 1) {
                continue;
            }
            if ($groupIds->isNotEmpty()) {
                $existing = CaptureGroup::find($groupIds->first());
                if (! $existing || $existing->profile_id !== $profile->id || $existing->members()->count() > $members->whereNotNull('capture_group_id')->count()) {
                    continue;
                }
            }
            $primary = $this->primary(profile: $profile, members: $members);
            if (! $primary) {
                continue;
            }
            $ids = $members->pluck('id')->values()->all();
            $fingerprint = hash('sha256', json_encode([$profile->toArray(), $members->map(fn ($a) => [$a->id, $a->file_name, $a->path, $a->updated_at, $a->capture_group_id])->all()]));
            $groups[] = ['stem' => (string) $stem, 'asset_ids' => $ids, 'primary_asset_id' => $primary->id,
                'files'         => $members->pluck('file_name')->values()->all(), 'fingerprint' => $fingerprint];
        }

        return $groups;
    }

    private function primary(CaptureProfile $profile, Collection $members): ?Asset
    {
        $groupId = $members->pluck('capture_group_id')->filter()->first();
        $group = $groupId ? CaptureGroup::find($groupId) : null;
        if ($group?->manual_primary) {
            return $members->firstWhere('id', $group->primary_asset_id);
        }
        foreach ($profile->priority as $extension) {
            if ($asset = $members->first(fn ($a) => strtolower($a->extension) === $extension)) {
                return $asset;
            }
        }

        return null;
    }

    public function apply(CaptureProfile $profile, int $directoryId, array $groups): array
    {
        return DB::transaction(function () use ($profile, $directoryId, $groups) {
            $profile = CaptureProfile::query()->lockForUpdate()->findOrFail($profile->id);
            Directory::query()->lockForUpdate()->findOrFail($directoryId);
            $folderAssets = Asset::query()->whereHas('directories', fn ($q) => $q->where('dam_directories.id', $directoryId));
            CaptureGroup::whereIn('id', (clone $folderAssets)->whereNotNull('capture_group_id')->pluck('capture_group_id'))->orderBy('id')->lockForUpdate()->get();
            $folderAssets->orderBy('id')->lockForUpdate()->get(['id']);
            $current = collect($this->preview(profile: $profile, directoryId: $directoryId))->keyBy('fingerprint');
            $result = [];
            foreach ($groups as $proposed) {
                $candidate = $current->get($proposed['fingerprint'] ?? '');
                abort_unless($candidate && $candidate === $proposed, 409, 'Files or profile changed. Preview again.');
                $members = Asset::query()->whereIn('id', $candidate['asset_ids'])->lockForUpdate()->get();
                $groupId = $members->pluck('capture_group_id')->filter()->first();
                $group = $groupId ? CaptureGroup::findOrFail($groupId) : new CaptureGroup(['profile_id' => $profile->id]);
                $group->primary_asset_id = $candidate['primary_asset_id'];
                $group->save();
                Asset::query()->whereIn('id', $candidate['asset_ids'])->update(['capture_group_id' => $group->id]);
                $result[] = $group->id;
                $this->log(event: 'group_applied', ctx: ['group_id' => $group->id, 'asset_ids' => $candidate['asset_ids'], 'primary_asset_id' => $group->primary_asset_id]);
            }

            return $result;
        });
    }

    public function autoGroup(int $assetId): void
    {
        $asset = Asset::find($assetId);
        if (! $asset || $asset->capture_grouping_disabled) {
            return;
        }
        $directories = $asset->directories()->pluck('dam_directories.id');
        if ($directories->count() !== 1) {
            return;
        }
        $profiles = CaptureProfile::where('enabled', true)->get()->filter(fn ($p) => in_array(strtolower($asset->extension), $p->extensions, true));
        if ($profiles->count() !== 1) {
            return;
        }
        $profile = $profiles->first();
        DB::transaction(function () use ($profile, $directories, $asset) {
            $profile = CaptureProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if (! $profile->enabled) {
                return;
            }
            Directory::query()->lockForUpdate()->findOrFail($directories->first());
            $groups = collect($this->preview(profile: $profile, directoryId: $directories->first()))
                ->filter(fn ($g) => in_array($asset->id, $g['asset_ids'], true))
                ->filter(function ($g) use ($profile) {
                    $extensions = Asset::whereIn('id', $g['asset_ids'])->pluck('extension')->map(fn ($e) => strtolower($e));

                    return ! CaptureProfile::where('enabled', true)->whereKeyNot($profile->id)->get()
                        ->contains(fn ($other) => $extensions->intersect($other->extensions)->isNotEmpty());
                })->values()->all();
            $this->apply(profile: $profile, directoryId: $directories->first(), groups: $groups);
        });
    }

    /** Storage changes preserve a capture; changed membership detaches just that record. */
    public function detachChangedMember(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $group = CaptureGroup::query()->lockForUpdate()->find($asset->capture_group_id);
            if (! $group) {
                return;
            }
            $others = $group->members()->whereKeyNot($asset->id)->with('directories')->orderBy('id')->get();
            $directories = $asset->directories()->pluck('dam_directories.id');
            $profile = $group->profile;
            $matches = $directories->count() === 1 && in_array(strtolower($asset->extension), $profile->extensions, true)
                && $others->every(fn ($other) => $other->directories->count() === 1
                    && $other->directories->first()->id === $directories->first()
                    && pathinfo($other->file_name, PATHINFO_FILENAME) === pathinfo($asset->file_name, PATHINFO_FILENAME));
            if ($matches) {
                return;
            }

            Asset::whereKey($asset->id)->update(['capture_group_id' => null]);
            $asset->capture_group_id = null;
            $primary = $group->manual_primary ? $others->firstWhere('id', $group->primary_asset_id) : null;
            if (! $primary) {
                foreach ($profile->priority as $extension) {
                    $primary = $others->first(fn ($other) => strtolower($other->extension) === $extension);
                    if ($primary) {
                        break;
                    }
                }
            }
            if ($others->count() < 2 || ! $primary) {
                $group->members()->update(['capture_group_id' => null]);
                $group->delete();
            } else {
                $group->update(['primary_asset_id' => $primary->id,
                    'manual_primary'               => $group->manual_primary && $group->primary_asset_id === $primary->id]);
            }
            $this->log(event: 'member_detached', ctx: ['asset_id' => $asset->id, 'group_id' => $group->id]);
        });
    }

    public function ungroup(int $assetId): void
    {
        DB::transaction(function () use ($assetId) {
            $asset = Asset::findOrFail($assetId);
            if (! $asset->capture_group_id) {
                return;
            }
            $group = CaptureGroup::query()->lockForUpdate()->find($asset->capture_group_id);
            if (! $group) {
                return;
            }
            $group->members()->update(['capture_group_id' => null, 'capture_grouping_disabled' => true]);
            $group->delete();
            $this->log(event: 'group_removed', ctx: ['group_id' => $group->id]);
        });
    }

    private function log(string $event, array $ctx): void
    {
        if (! config('app.debug')) {
            return;
        }
        Log::debug(json_encode(['ts' => now()->toIso8601String(), 'run_id' => request()->header('X-Request-ID') ?: (string) Str::uuid(), 'level' => 'debug', 'event' => $event, 'loc' => self::class, 'ctx' => $ctx]));
    }
}
