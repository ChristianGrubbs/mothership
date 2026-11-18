<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\GlbPrimaryView;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Traits\AssetAccessControl;

class ModelPrimaryViewController
{
    use AssetAccessControl;

    private function authorize(int $id, bool $write = false): Asset
    {
        $asset = $this->damVisibleAssetOrFail($id);
        abort_if($write && ! $this->damCanEditAsset($id), 403);
        abort_unless(strtolower($asset->extension ?? '') === 'glb', 422, 'Primary views support GLB models only.');

        return $asset;
    }

    private function currentFile(Asset $asset): AssetFile
    {
        return $asset->files()->with('fileObject')->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    }

    private function state(Asset $asset): ?array
    {
        $state = $asset->meta_data['glb_primary_view'] ?? null;

        return is_array($state) && ($state['sha256'] ?? '') === $this->currentFile($asset)->fileObject->sha256 ? $state : null;
    }

    private function revision(Asset $asset): string
    {
        return hash('sha256', $asset->path.'|'.$this->currentFile($asset)->fileObject->sha256.'|'.($this->state($asset)['revision'] ?? ''));
    }

    public function show(int $id)
    {
        $asset = $this->authorize($id);

        return response()->json(['revision'=>$this->revision($asset), 'can_edit'=>$this->damCanEditAsset($id) && app(RoleBuilder::class)->refusal($asset) === null, 'view'=>$this->state($asset)['view'] ?? null]);
    }

    public function save(Request $request, int $id)
    {
        $this->authorize($id, true);
        $data = $request->validate([
            'revision'   => 'required|string|size:64', 'rotation'=>'required|array|size:4', 'rotation.*'=>'required|numeric|between:-1,1',
            'translation'=> 'required|array|size:3', 'translation.*'=>'required|numeric|between:-1000000000,1000000000',
            'view'       => 'required|array:distance,aspect', 'view.distance'=>'required|numeric|gt:0|lte:1000000000', 'view.aspect'=>'required|numeric|between:0.01,100',
        ]);
        $rotation = array_map('floatval', array_values($data['rotation']));
        abort_unless(abs(array_sum(array_map(fn ($n) => $n * $n, $rotation)) - 1) < 0.0001, 422, 'Invalid orientation.');

        return $this->change($request, $id, $data);
    }

    public function restore(Request $request, int $id)
    {
        $this->authorize($id, true);
        $request->validate(['revision'=>'required|string|size:64']);

        return $this->change($request, $id, null);
    }

    private function change(Request $request, int $id, ?array $data)
    {
        $disk = Directory::getAssetDisk();

        return DB::transaction(function () use ($request, $id, $data, $disk) {
            $asset = Asset::lockedForGraph()->findOrFail($id);
            // Saving or restoring the primary view rewrites the model's own file in place: own items only.
            abort_if(app(RoleBuilder::class)->refusal($asset) !== null, 403, trans('dam::app.admin.permissions.own-only-change'));
            abort_unless(hash_equals($this->revision($asset), $request->input('revision')), 409, 'A newer model exists. Reopen the preview before saving.');
            $state = $this->state($asset);
            $file = $this->currentFile($asset);
            if ($data === null) {
                abort_unless($state, 409, 'This model has no saved primary view.');
            }
            if ($state) {
                $file = $asset->files()->with('fileObject')->findOrFail($state['source_file_id']);
            }
            $transform = $data ? app(GlbPrimaryView::class)->compose(delta: $data, previous: $state) : null;
            // Read through the seam (ADR 0009): the file's own FileObject, never a Library path,
            // which a folder move can leave stale.
            try {
                $input = app(ContentStore::class)->open($file->fileObject);
            } catch (ContentUnavailable) {
                abort(422, 'The model file is unavailable.');
            }
            $output = tmpfile();
            abort_unless(is_resource($output), 500, 'Could not prepare the model.');
            try {
                if ($data) {
                    app(GlbPrimaryView::class)->transform(input: $input, output: $output,
                        rotation: $transform['rotation'], translation: $transform['translation']);
                } else {
                    abort_if(stream_copy_to_stream($input, $output) === false, 500);
                    rewind($output);
                }
                $hashContext = hash_init('sha256');
                hash_update_stream($hashContext, $output);
                $sha = hash_final($hashContext);
                $size = ftell($output);
                // The corrected model goes into a blob, never the assets/.models physical path (ADR 0009
                // slice 3): sha and size are already known, so putLocal ingests from the same local temp.
                $object = app(ContentStore::class)->putLocal(
                    disk: $disk,
                    localPath: stream_get_meta_data($output)['uri'],
                    sha256: $sha,
                    extension: 'glb',
                );
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            } finally {
                fclose($input);
                fclose($output);
            }
            $meta = $asset->meta_data ?? [];
            if ($data) {
                $meta['glb_primary_view'] = array_merge($transform, ['source_file_id'=> $state['source_file_id'] ?? $file->id, 'sha256'=>$sha,
                    'revision'                                                       => (string) Str::uuid(), 'view'=>['distance'=>(float) $data['view']['distance'], 'aspect'=>(float) $data['view']['aspect']]]);
            } else {
                unset($meta['glb_primary_view']);
            }
            // The Library path is unchanged: this always corrects an existing model in place, never
            // names a new folder-tree location (unlike the image editor's "save as new" case).
            $asset->fill(['file_size'=>$size, 'meta_data'=>$meta]);
            $asset->withGraphContent($object);
            $asset->save();
            $this->trace($data ? 'saved' : 'restored', $id);

            // `checksum` names the bytes the entry now holds: the viewer that adopts this view in place captures against it.
            return response()->json(['revision'=>$this->revision($asset), 'can_edit'=>true, 'view'=>$meta['glb_primary_view']['view'] ?? null, 'checksum'=>$sha]);
        });
    }

    private function trace(string $event, int $id): void
    {
        if (! config('app.debug')) {
            return;
        }
        try {
            file_put_contents(storage_path('logs/model-primary-view.jsonl'), json_encode(['ts'=>now()->toIso8601String(), 'run_id'=>(string) Str::uuid(), 'level'=>'info', 'event'=>$event, 'loc'=>'model-primary-view', 'ctx'=>RequestId::ctx(['asset_id'=>$id])], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Optional diagnostics never change the save outcome.
        }
    }
}
