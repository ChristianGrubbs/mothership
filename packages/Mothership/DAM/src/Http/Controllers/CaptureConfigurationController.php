<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CaptureGroup;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\DamConfiguration;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\CaptureGroupingService;
use Mothership\DAM\Services\DetailLayoutService;
use Mothership\DAM\Traits\AssetAccessControl;

class CaptureConfigurationController extends Controller
{
    use AssetAccessControl;

    public function index(DetailLayoutService $layouts)
    {
        $this->authorizeConfiguration(write: false);

        return view('dam::configuration.capture', ['initial' => [
            'layouts'     => $layouts->layouts(), 'fields' => $layouts->catalog(), 'global' => $layouts->isGlobal(),
            'profiles'    => CaptureProfile::orderBy('id')->get(),
            'directories' => Directory::query()->orderBy('name')->get(['id', 'name']),
            'can_update'  => bouncer()->hasPermission('dam.configuration.update'),
        ]]);
    }

    public function layouts(Request $request)
    {
        $this->authorizeConfiguration();
        $data = $request->validate([
            'global'               => 'sometimes|boolean',
            'layouts'              => 'present|array|max:100', 'layouts.*.match' => ['required', 'string', 'distinct', 'regex:/^(\*|[a-z0-9_-]{1,32})$/'],
            'layouts.*.fields'     => 'present|array|max:100', 'layouts.*.fields.*' => 'required|string|max:255',
            'layouts.*.hide_empty' => 'sometimes|boolean', // legacy flag: empty rows are always hidden now
        ]);
        foreach ($data['layouts'] as $layout) {
            abort_unless(count($layout['fields']) === count(array_unique($layout['fields'])), 422, 'Choose each field only once.');
            foreach ($layout['fields'] as $key) {
                abort_unless(isset(DetailLayoutService::LABELS[$key]) || (str_starts_with($key, 'property:') && strlen($key) > 9), 422, 'Unknown field.');
            }
        }
        $global = $data['global'] ?? app(DetailLayoutService::class)->isGlobal();
        abort_if($global && ! collect($data['layouts'])->contains('match', '*'), 422, 'A global layout is required.');
        DB::transaction(function () use ($data, $global) {
            DamConfiguration::updateOrCreate(['key' => 'DAM_DETAIL_LAYOUTS'], ['value' => json_encode($data['layouts'])]);
            DamConfiguration::updateOrCreate(['key' => 'DAM_DETAIL_LAYOUT_GLOBAL'], ['value' => $global ? '1' : '0']);
        });

        return response()->json(['saved' => true]);
    }

    public function saveProfile(Request $request, ?int $profile = null)
    {
        $this->authorizeConfiguration();
        $data = $request->validate([
            'name'       => 'required|string|max:100', 'enabled' => 'required|boolean',
            'extensions' => 'required|array|min:2|max:50', 'extensions.*' => ['required', 'distinct', 'regex:/^[a-z0-9]{1,16}$/'],
            'priority'   => 'required|array|min:1|max:50', 'priority.*' => ['required', 'distinct', Rule::in($request->input('extensions', []))],
        ]);
        $model = DB::transaction(function () use ($profile, $data) {
            $model = $profile ? CaptureProfile::query()->lockForUpdate()->findOrFail($profile) : new CaptureProfile;
            $model->fill($data)->save();

            return $model;
        });

        return response()->json(['profile' => $model], $profile ? 200 : 201);
    }

    public function preview(Request $request, CaptureGroupingService $groups)
    {
        $this->authorizeConfiguration();
        [$profile, $directory] = $this->previewInput($request);

        return response()->json(['groups' => $groups->preview(profile: $profile, directoryId: $directory)]);
    }

    public function apply(Request $request, CaptureGroupingService $groups)
    {
        $this->authorizeConfiguration();
        [$profile, $directory] = $this->previewInput($request);
        $data = $request->validate(['groups' => 'required|array|max:1000', 'groups.*.fingerprint' => 'required|string|size:64']);
        $proposals = $request->input('groups');
        foreach ($proposals as $proposal) {
            foreach (($proposal['asset_ids'] ?? []) as $id) {
                $this->damVisibleAssetOrFail($id);
            }
        }

        return response()->json(['group_ids' => $groups->apply(profile: $profile, directoryId: $directory, groups: $proposals)]);
    }

    private function previewInput(Request $request): array
    {
        $data = $request->validate(['profile_id' => 'required|integer|exists:dam_capture_profiles,id', 'directory_id' => ['required', 'integer', new VisibleDirectory]]);
        abort_unless($this->damPermissionService()->bypass() || $this->damPermissionService()->canAccess($data['directory_id']), 403);

        return [CaptureProfile::findOrFail($data['profile_id']), (int) $data['directory_id']];
    }

    public function showCapture(int $asset)
    {
        abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403);
        $this->damAuthorizeAsset($asset);

        return response()->json($this->captureData(Asset::findOrFail($asset)));
    }

    private function captureData(Asset $asset): array
    {
        $group = $asset->capture_group_id ? CaptureGroup::with('profile')->find($asset->capture_group_id) : null;
        $members = $group ? $group->members()->orderBy('id')->get() : collect([$asset]);
        $members = $members->filter(fn ($a) => $this->damCanAccessAsset($a->id));

        return ['group'  => $group, 'profiles' => CaptureProfile::orderBy('name')->get(),
            'can_manage' => bouncer()->hasPermission('dam.configuration.update') && $this->damCanEditAsset($asset->id),
            'members'    => $members->map(fn ($a) => ['id' => $a->id, 'file_name' => $a->file_name, 'extension' => $a->extension,
                'primary'                                  => ! $group || $group->primary_asset_id === $a->id,
                'preview_url'                              => route('admin.dam.assets.edit', $a->id), 'download_url' => route('admin.dam.assets.download', $a->id)])->values()];
    }

    public function updateCapture(Request $request, int $asset, CaptureGroupingService $groups)
    {
        $this->authorizeConfiguration();
        $this->damVisibleAssetOrFail($asset);
        abort_unless($this->damCanEditAsset($asset), 403);
        // VisibleAsset, not `exists`: a hidden primary fails exactly as a missing one does.
        $data = $request->validate(['primary_asset_id' => ['sometimes', 'required', 'integer', new VisibleAsset], 'profile_id' => 'sometimes|required|integer|exists:dam_capture_profiles,id']);
        DB::transaction(function () use ($asset, $data, $groups) {
            $model = Asset::findOrFail($asset);
            $group = $model->capture_group_id ? CaptureGroup::query()->lockForUpdate()->findOrFail($model->capture_group_id) : null;
            if (isset($data['profile_id'])) {
                $profile = CaptureProfile::findOrFail($data['profile_id']);
                if (! $group) {
                    $directories = $model->directories()->pluck('dam_directories.id');
                    abort_unless($directories->count() === 1, 422, 'Choose an asset in one folder.');
                    $neighbors = Asset::query()->whereNull('capture_group_id')
                        ->whereHas('directories', fn ($q) => $q->where('dam_directories.id', $directories->first()))
                        ->with('directories')->get()->filter(fn ($a) => $a->directories->count() === 1
                            && pathinfo($a->file_name, PATHINFO_FILENAME) === pathinfo($model->file_name, PATHINFO_FILENAME)
                            && in_array(strtolower($a->extension), $profile->extensions, true));
                    foreach ($neighbors as $neighbor) {
                        $this->damAuthorizeAsset($neighbor->id);
                    }
                    Asset::whereIn('id', $neighbors->pluck('id'))->update(['capture_grouping_disabled' => false]);
                    $candidates = collect($groups->preview(profile: $profile, directoryId: $directories->first()))->filter(fn ($g) => in_array($asset, $g['asset_ids'], true))->values()->all();
                    abort_if(empty($candidates), 422, 'No unambiguous matching companion files in this folder.');
                    $groups->apply(profile: $profile, directoryId: $directories->first(), groups: $candidates);
                    $group = CaptureGroup::findOrFail($model->fresh()->capture_group_id);
                } else {
                    $members = $group->members()->get();
                    foreach ($members as $member) {
                        $this->damAuthorizeAsset($member->id);
                        abort_unless(in_array(strtolower($member->extension), $profile->extensions, true), 422, 'Profile does not cover every file.');
                    }
                    $primary = null;
                    foreach ($profile->priority as $extension) {
                        $primary = $members->first(fn ($m) => strtolower($m->extension) === $extension);
                        if ($primary) {
                            break;
                        }
                    }
                    abort_unless($primary, 422, 'Profile has no primary candidate.');
                    $group->update(['profile_id' => $profile->id, 'primary_asset_id' => $primary->id, 'manual_primary' => false]);
                }
            }
            if (isset($data['primary_asset_id'])) {
                abort_unless($group && $group->members()->whereKey($data['primary_asset_id'])->exists(), 422, 'Primary must belong to this capture.');
                foreach ($group->members()->get() as $member) {
                    $this->damAuthorizeAsset($member->id);
                }
                $group->update(['primary_asset_id' => $data['primary_asset_id'], 'manual_primary' => true]);
            }
        });

        return response()->json($this->captureData(Asset::findOrFail($asset)));
    }

    public function ungroup(int $asset, CaptureGroupingService $groups)
    {
        $this->authorizeConfiguration();
        $this->damAuthorizeAsset($asset);
        abort_unless($this->damCanEditAsset($asset), 403);
        $model = Asset::findOrFail($asset);
        if ($model->capture_group_id) {
            foreach (Asset::where('capture_group_id', $model->capture_group_id)->get() as $member) {
                $this->damAuthorizeAsset($member->id);
            }
        }
        $groups->ungroup(assetId: $asset);

        return response()->json(['ungrouped' => true]);
    }

    private function authorizeConfiguration(bool $write = true): void
    {
        abort_unless(bouncer()->hasPermission($write ? 'dam.configuration.update' : 'dam.configuration.index'), 403);
    }
}
