<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Symfony\Component\Process\Process;

/**
 * The first page of a PDF's bytes as the content-keyed poster (`derivatives/<sha>/poster.jpg`, ADR 0009): the one
 * renderer GeneratePdfThumbnail (an asset's card) and a stacked file's thumbnail both use, so a PDF-compatible
 * Illustrator file is drawn by the same safe renderer (pdftoppm, first page only) as a PDF.
 */
final class PdfPoster
{
    /** The poster's path once it exists: made now when absent, null when the bytes could not be rendered. */
    public static function ensure(FileObject $object): ?string
    {
        $path = DerivativeImages::poster((string) $object->sha256);

        return Storage::disk(Directory::getAssetDisk())->exists($path) ? $path : self::render($object);
    }

    /** Renders the poster and returns its path, or null (logged) when the bytes are missing or pdftoppm fails. */
    public static function render(FileObject $object): ?string
    {
        $store = app(ContentStore::class);
        $posterPath = DerivativeImages::poster((string) $object->sha256);
        $local = null;
        $tmpPrefix = tempnam(sys_get_temp_dir(), 'dampdf_thumb_');
        $tmpPng = $tmpPrefix.'.png';

        try {
            $local = $store->localPath($object, 'pdf');

            $process = new Process(['pdftoppm', '-png', '-f', '1', '-l', '1', '-r', '80', '-singlefile', $local->path, $tmpPrefix]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful() || ! file_exists($tmpPng)) {
                Log::warning('DAM PDF thumbnail: pdftoppm failed.', ['file_object' => $object->id, 'stderr' => $process->getErrorOutput()]);

                return null;
            }

            $jpegData = (string) (new ImageManager(new Driver))->decode(file_get_contents($tmpPng))
                ->scale(width: 300)
                ->encode(new JpegEncoder(quality: 80));

            Storage::disk(Directory::getAssetDisk())->put($posterPath, $jpegData);

            return $posterPath;
        } catch (ContentUnavailable $e) {
            Log::warning('DAM PDF thumbnail: source file missing.', ['file_object' => $object->id, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('DAM PDF thumbnail: exception while generating.', ['file_object' => $object->id, 'message' => $e->getMessage()]);
        } finally {
            $local?->release();
            @unlink($tmpPng);
            @unlink($tmpPrefix);
        }

        return null;
    }
}
