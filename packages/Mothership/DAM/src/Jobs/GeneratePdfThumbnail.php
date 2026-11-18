<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\PdfPoster;

class GeneratePdfThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(protected int $assetId) {}

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset || ! AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type)) {
            return;
        }

        $store = app(ContentStore::class);

        try {
            $object = $store->current($asset);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM PDF thumbnail: source file missing.', [
                'asset' => $asset->id, 'message' => $e->getMessage(),
            ]);

            return;
        }

        $posterPath = PdfPoster::render($object);

        if ($posterPath === null) {
            return;
        }

        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $meta['thumbnail_path'] = $posterPath;
        $asset->meta_data = $meta;
        $asset->saveQuietly();
    }
}
