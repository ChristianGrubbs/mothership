<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\DAM\Services\GenerationTitler;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\SharedHiggsfield;
use RuntimeException;

class CompleteStylePressGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $timeout = 80;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $row = DB::table('dam_home_generations')->where('id', $this->id)->first();
        if (! $row || ! $row->inputs || $row->save_status === 'saved' || in_array($row->status, ['failed', 'nsfw', 'canceled', 'cancelled'])) {
            return;
        }
        $root = storage_path('app/private/stylepress/outbox/'.$this->id);
        if (! is_dir($root) && ! mkdir($root, 0770, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create generation package.');
        }
        $lock = fopen($root.'/.save.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->release(30);

            return;
        }
        try {
            // Provider completion is persisted independently of local import. A save
            // retry never submits or charges for another image.
            if ($row->status !== 'completed') {
                $poll = $this->poll(row: $row);
                DB::table('dam_home_generations')->where('id', $this->id)->update(['status' => $poll['status'], 'result_url' => $poll['result_url'] ?? null, 'updated_at' => now()]);
                if ($poll['status'] !== 'completed') {
                    if (! in_array($poll['status'], ['failed', 'nsfw', 'canceled', 'cancelled'])) {
                        $this->release(15);
                    }

                    return;
                }
                $row->status = 'completed';
                $row->result_url = $poll['result_url'];
            }
            $manifestPath = $root.'/generation.json';
            if (! is_file($manifestPath)) {
                $this->package(row: $row, root: $root);
            }
            try {
                // A queue worker has no session, so the requester comes from the generation row rather than
                // from auth(): the saved asset and its generation folders are owned by whoever asked for it
                // (ADR 0010, spec "Inherit on create").
                $result = app(GenerationPackageImporter::class)->importManifest(manifestPath: $manifestPath, ownerId: (int) $row->admin_id);
            } catch (ValidationException $invalid) {
                // A stored manifest that no longer validates (for example a missing checksum) is rebuilt from the
                // package on the next attempt; a valid downloaded output is reused, never downloaded again.
                @unlink($manifestPath);
                throw new RuntimeException('Generation manifest was invalid and will be rebuilt: '.implode(' ', array_map(fn ($messages) => implode(' ', $messages), $invalid->errors())), 0, $invalid);
            }
            if (! ($result['ok'] ?? false)) {
                throw new RuntimeException('Generation package import is pending.');
            }
            DB::table('dam_home_generations')->where('id', $this->id)->update(['asset_id' => $result['asset_id'], 'save_status' => 'saved', 'save_error' => null, 'updated_at' => now()]);
            HomeTelemetry::record(event: 'generation.saved', ctx: ['generation_id' => $this->id, 'asset_id' => $result['asset_id']]);
        } catch (\Throwable $e) {
            DB::table('dam_home_generations')->where('id', $this->id)->update(['save_error' => 'Completion or saving is temporarily unavailable; the worker will retry. ('.$this->describeFailure($e).')', 'updated_at' => now()]);
            HomeTelemetry::record(event: 'generation.retry', ctx: ['generation_id' => $this->id, 'phase' => $row->status === 'completed' ? 'save' : 'provider', 'exception' => get_class($e)]);
            $this->release(60);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A short, safe reason for the retry: the exception class plus a message only for our own
     * RuntimeExceptions and validation rebuilds. Provider/HTTP and database exceptions can carry
     * signed URLs or prompt-bearing SQL bindings, so those keep the class only; any URL is redacted.
     */
    private function describeFailure(\Throwable $e): string
    {
        $class = class_basename($e);
        $safe = $e instanceof RuntimeException && ! $e instanceof RequestException && ! $e instanceof ConnectionException && ! $e instanceof QueryException;
        if (! $safe) {
            return $class;
        }
        $message = preg_replace('~https?://\S+~i', '[url]', preg_replace('/\s+/', ' ', $e->getMessage()));

        return mb_substr($class.': '.$message, 0, 180);
    }

    private function poll(object $row): array
    {
        if (str_starts_with($row->status_url, 'higgsfield-cli:')) {
            return app(SharedHiggsfield::class)->poll(id: $row->request_id);
        }
        $base = config('stylepress.higgsfield.api_base_url');
        if (parse_url($row->status_url, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) {
            throw new RuntimeException('Invalid provider status address.');
        }
        $body = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(25)
            ->withHeaders(['Authorization' => 'Key '.config('stylepress.higgsfield.api_key_id').':'.config('stylepress.higgsfield.api_key_secret')])->get($row->status_url)->throw()->json();
        $status = strtolower($body['status'] ?? '');
        if (! in_array($status, ['queued', 'in_progress', 'running', 'completed', 'failed', 'nsfw', 'canceled', 'cancelled'])) {
            throw new RuntimeException('Unknown provider state.');
        }
        $url = $body['result_url'] ?? $body['url'] ?? $body['images'][0]['url'] ?? $body['images'][0] ?? null;

        return ['status' => $status, 'result_url' => $url];
    }

    private function package(object $row, string $root): void
    {
        if (! is_string($row->result_url) || parse_url($row->result_url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('No completed image address.');
        }
        // A previous attempt may already hold a valid output (a retry never re-downloads a good file, and on a
        // host bind mount a rename onto an existing file is not reliable, so the target is unlinked first).
        $types = ['output.png' => IMAGETYPE_PNG, 'output.jpeg' => IMAGETYPE_JPEG, 'output.webp' => IMAGETYPE_WEBP];
        $existing = collect($types)->keys()->map(fn ($file) => $root.'/'.$file)
            ->first(fn ($file) => is_file($file) && filesize($file) > 0 && ($detected = @getimagesize($file)) && $detected[2] === $types[basename($file)]);
        if ($existing) {
            $info = getimagesize($existing);
            $name = basename($existing);
            $sha256 = hash_file('sha256', $existing);
        } else {
            $temp = $root.'/output.download';
            @unlink($temp);
            $response = Http::connectTimeout(5)->timeout(45)->withOptions(['sink' => $temp])->get($row->result_url);
            try {
                $response->throw();
            } finally {
                // Close the download handle explicitly. The client response keeps the sink stream open until
                // garbage collection, and on a host drive mount a file renamed while a handle is still open
                // is invisible under its new name to this process until that handle closes — in a long-lived
                // queue worker that left a completed output "missing" for an hour.
                $response->toPsrResponse()->getBody()->close();
                unset($response);
            }
            $info = @getimagesize($temp);
            if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP])) {
                @unlink($temp);
                throw new RuntimeException('Provider result is not a supported image.');
            }
            $name = 'output'.image_type_to_extension($info[2]);
            // Hash the download before renaming: on a host bind mount a just-renamed file can be
            // unreadable for a moment, which once produced a manifest with `false` as the checksum.
            $sha256 = hash_file('sha256', $temp);
            @unlink($root.'/'.$name);
            if (! rename($temp, $root.'/'.$name)) {
                throw new RuntimeException('Cannot persist output.');
            }
            clearstatcache(true, $root.'/'.$name);
            if (! is_file($root.'/'.$name)) {
                throw new RuntimeException('Persisted output is not visible yet; the save will retry.');
            }
        }
        if (! is_string($sha256) || ! preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            throw new RuntimeException('Output checksum is unavailable; the save will retry.');
        }
        $inputs = json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR);
        $references = [];
        foreach ($inputs['references'] as $index => $reference) {
            if (! is_dir($root.'/references')) {
                mkdir($root.'/references', 0770, true);
            }
            $path = 'references/'.$index.'.'.pathinfo($reference['absolute_path'], PATHINFO_EXTENSION);
            if (! copy($reference['absolute_path'], $root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $reference['sha256']) {
                throw new RuntimeException('A saved reference has changed.');
            }
            $references[] = ['path' => $path, 'name' => $reference['name'], 'role' => $reference['role'], 'sha256' => $reference['sha256'], 'asset_id' => isset($reference['asset_id']) ? (int) $reference['asset_id'] : null];
        }
        $divisor = $info[0];
        $remaining = $info[1];
        while ($remaining !== 0) {
            [$divisor, $remaining] = [$remaining, $divisor % $remaining];
        }
        $inputs['settings']['actual_aspect_ratio'] = ($info[0] / $divisor).':'.($info[1] / $divisor);
        $inputs['settings']['actual_width'] = $info[0];
        $inputs['settings']['actual_height'] = $info[1];
        // The saved asset gets a short AI-written title (or a one-line fallback); the full request stays in the manifest.
        $titled = app(GenerationTitler::class)->title(style: $inputs['style'], originalPrompt: $inputs['original_prompt'], enhancedPrompt: $inputs['enhanced_prompt'] ?? null);
        $inputs['settings']['title_source'] = $titled['source'];
        $manifest = ['schema_version' => 1, 'generation_id' => 'mothership-'.$this->id, 'title' => $titled['title'], 'kind' => 'image', 'provider' => 'higgsfield-generate', 'model' => str_starts_with($row->status_url, 'higgsfield-cli:') ? ($inputs['settings']['model'] ?? 'gpt_image_2') : config('stylepress.higgsfield.default_model'), 'prompt' => $inputs['prompt'], 'original_prompt' => $inputs['original_prompt'], 'enhanced_prompt' => $inputs['enhanced_prompt'], 'style' => $inputs['style'], 'settings' => $inputs['settings'], 'source' => ['note_path' => null, 'job_id' => $row->request_id, 'url' => $row->result_url, 'generated_at' => now()->toIso8601String()], 'output' => ['path' => $name, 'sha256' => $sha256, 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1]], 'references' => $references];
        if (file_put_contents($root.'/generation.json.tmp', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write the generation manifest.');
        }
        @unlink($root.'/generation.json');
        if (! rename($root.'/generation.json.tmp', $root.'/generation.json')) {
            throw new RuntimeException('Cannot persist the generation manifest.');
        }
        // The device mirror replaces this receipt only after archive note+MOC verification.
        if (! is_file($root.'/archive-receipt.json')) {
            file_put_contents($root.'/archive-receipt.json', json_encode(['status' => 'pending', 'generation_id' => $manifest['generation_id']]));
        }
    }
}
