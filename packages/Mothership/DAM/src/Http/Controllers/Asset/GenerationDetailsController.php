<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\GenerationAccess;
use Symfony\Component\HttpFoundation\HeaderUtils;

class GenerationDetailsController extends Controller
{
    public function show(int $asset)
    {
        return response()->json($this->details(assetId: $asset));
    }

    public function view(int $asset)
    {
        return view('dam::asset.generation', $this->details(assetId: $asset));
    }

    /** The generated image itself, streamed inline for the details page and the StylePress result panel. */
    public function output(int $asset)
    {
        $this->details(assetId: $asset);
        $record = Asset::findOrFail($asset);
        $contentStore = app(ContentStore::class);

        try {
            $object = $contentStore->current($record);
        } catch (ContentUnavailable) {
            abort(404);
        }

        $name = $record->file_name ?: 'output';

        // ?download=1 serves the same bytes as an attachment under the generation's access rules, so an
        // originating owner without folder access can save the file as well as view it.
        try {
            return $contentStore->deliver(
                object: $object,
                filename: $name,
                disposition: request()->boolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
                mimeType: $record->mime_type ?: ($object->mime_type ?: null),
                headers: ['Cache-Control' => 'private, no-store'],
            );
        } catch (ContentUnavailable) {
            abort(404);
        }
    }

    public function file(int $asset, int $file)
    {
        $details = $this->details(assetId: $asset);
        $allowed = array_column([...$details['reference_urls'], ...$details['attachments']], 'file_id');
        abort_unless(in_array($file, $allowed, true), 404);
        $attachment = Asset::findOrFail($asset)->files()->with('fileObject')->findOrFail($file);
        $object = $attachment->fileObject;
        abort_unless($object !== null, 404);
        $inline = in_array($object->mime_type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'text/plain', 'text/markdown', 'application/json', 'video/mp4', 'video/webm'], true);

        try {
            return app(ContentStore::class)->deliver(
                object: $object,
                filename: basename($attachment->label ?? 'reference'),
                disposition: $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                mimeType: $object->mime_type ?: null,
                headers: ['Cache-Control' => 'private, no-store'],
            );
        } catch (ContentUnavailable) {
            abort(404);
        }
    }

    private function details(int $assetId): array
    {
        $asset = Asset::findOrFail($assetId);
        $generation = app(GenerationAccess::class)->find(assetId: $assetId);
        $manifest = $generation->manifest;
        $files = $asset->files()->with('fileObject')->get();
        $references = [];
        foreach ($manifest['references'] ?? [] as $reference) {
            $role = in_array($reference['role'] ?? 'source', ['source', 'mask', 'other'], true) ? ($reference['role'] ?? 'source') : 'source';
            $file = $files->first(fn ($file) => $file->fileObject?->sha256 === strtolower($reference['sha256'] ?? '') && $file->role === $role);
            if ($file) {
                $references[] = ['file_id'=>$file->id, 'name'=>$reference['name'] ?? basename($reference['path']), 'path'=>$reference['path'], 'role'=>$reference['role'] ?? 'source', 'sha256'=>$file->fileObject->sha256, 'mime'=>$file->fileObject->mime_type, 'url'=>route('admin.dam.assets.generation.file', ['asset'=>$assetId, 'file'=>$file->id])];
            }
        }
        $currentHashes = ['sidecar'=>$generation->manifest_sha256, 'prompt'=>hash('sha256', (string) ($manifest['prompt'] ?? ''))];
        $attachments = $files->filter(fn ($file) => isset($currentHashes[$file->role]) && $file->fileObject?->sha256 === $currentHashes[$file->role])->map(fn ($file) => ['file_id'=>$file->id, 'label'=>$file->label, 'url'=>route('admin.dam.assets.generation.file', ['asset'=>$assetId, 'file'=>$file->id])])->values()->all();

        return ['asset_id'=> $assetId, 'asset_title'=>$asset->title, 'file_name'=>$asset->file_name, 'manifest'=>$manifest, 'reference_urls'=>$references, 'attachments'=>$attachments,
            'output_url'  => route('admin.dam.assets.generation.output', $assetId), 'download_url'=>route('admin.dam.assets.generation.output', [$assetId, 'download' => 1]), 'is_image'=>str_starts_with((string) $asset->mime_type, 'image/'),
            'reuse_url'   => filled($manifest['style']['slug'] ?? null) && filled($manifest['style']['template'] ?? null)
                ? route('admin.home.tool', ['widget'=>'stylepress', 'path'=>'index.html', 'reuse_asset'=>$assetId]) : null];
    }
}
