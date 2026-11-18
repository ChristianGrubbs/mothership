<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Vectorization\Requests;
use Mothership\DAM\Vectorization\Vectorizer;
use RuntimeException;
use Throwable;

class VectorizeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 480;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $claimed = DB::table('dam_vectorizations')
            ->where('id', $this->id)
            ->where('status', 'queued')
            ->update(['status' => 'processing', 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $row = DB::table('dam_vectorizations')->where('id', $this->id)->first();
        $disk = Storage::disk('private');
        $root = 'vectorizations/results/'.$this->id;

        try {
            $result = app(Vectorizer::class)->convert(path: $disk->path($row->input_path));

            foreach (['svg', 'pdf'] as $format) {
                if (! $disk->put($root.'/result.'.$format, $result[$format])) {
                    throw new RuntimeException('Result storage failed.');
                }
            }

            app(Requests::class)->finish(id: $this->id, success: true);

            if (DB::table('dam_vectorizations')->where('id', $this->id)->value('status') !== 'completed') {
                $disk->deleteDirectory($root);
            }
        } catch (Throwable $error) {
            app(Requests::class)->finish(id: $this->id, success: false, code: 'conversion_failed');

            // Keep files if completion committed before its connection reported failure.
            if (DB::table('dam_vectorizations')->where('id', $this->id)->value('status') === 'failed') {
                $disk->deleteDirectory($root);
            }
        } finally {
            $disk->delete($row->input_path);
        }
    }

    public function failed(?Throwable $error): void
    {
        app(Requests::class)->finish(id: $this->id, success: false, code: 'worker_failed');
    }
}
