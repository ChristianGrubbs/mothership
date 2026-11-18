<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Optimization\OptimizationSource;
use Mothership\DAM\Optimization\Optimizer;
use Mothership\DAM\Optimization\OptimizerUnreachable;
use Mothership\DAM\Optimization\Requests;
use Mothership\DAM\Services\ContentStore;
use Throwable;

class OptimizeAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Longer than the client's own 960s HTTP timeout (config('optimization.timeout')).
    public int $timeout = 1020;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $claimed = DB::table('dam_optimizations')
            ->where('id', $this->id)
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $row = DB::table('dam_optimizations')->where('id', $this->id)->first();
        $staged = null;
        $result = null;

        try {
            $asset = Asset::findOrFail($row->asset_id);
            $source = OptimizationSource::resolve(asset: $asset, attachmentId: $row->attachment_id === null ? null : (int) $row->attachment_id);
            $object = $source->object;

            if (! hash_equals((string) $row->source_sha256, (string) $object->sha256)) {
                app(Requests::class)->finish(id: $this->id, success: false, error: 'source_changed');

                return;
            }

            $staged = app(ContentStore::class)->localPath($object, $source->extension);
            $options = json_decode((string) $row->options, true) ?? [];
            $result = app(Optimizer::class)->optimize(
                localPath: $staged->path,
                filename: $source->fileName,
                type: $row->type,
                options: $options,
            );

            if ($result['outcome'] === 'no_reduction') {
                app(Requests::class)->finish(id: $this->id, success: true, outcome: 'no_reduction', oldBytes: $result['old_bytes']);

                return;
            }

            // finish() -> AssetOutputs::publish() reads $result['path'] synchronously, inside this same
            // try block; the finally below only deletes it once that call has returned. No intermediate
            // copy through Storage::disk('private') and no file_get_contents(): ContentStore::putLocal()
            // streams the file straight into the content-addressed store (round-1 review finding).
            app(Requests::class)->finish(
                id: $this->id,
                success: true,
                outcome: 'optimized',
                oldBytes: $result['old_bytes'],
                newBytes: $result['new_bytes'],
                outputExtension: $result['extension'],
                outputPath: $result['path'],
            );
        } catch (ModelNotFoundException|ContentUnavailable) {
            app(Requests::class)->finish(id: $this->id, success: false, error: 'source_unavailable');
        } catch (OptimizerUnreachable) {
            // The bridge is down: no output exists and the original was only read, so nothing needs undoing.
            app(Requests::class)->finish(id: $this->id, success: false, error: 'provider_unreachable');
        } catch (Throwable) {
            app(Requests::class)->finish(id: $this->id, success: false, error: 'optimize_failed');
        } finally {
            $staged?->release();

            $outputPath = $result['path'] ?? null;

            if ($outputPath !== null) {
                @unlink($outputPath);
                @rmdir(dirname($outputPath));
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        app(Requests::class)->finish(id: $this->id, success: false, error: 'worker_failed');
    }
}
