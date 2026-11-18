<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PreviewKinds;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Turns an asset into frames and page text (spec §2: image 1024 px, video 4 keyframes, PDF text + page 1, DNG and 3D models → their stored preview or capture). */
class MediaSampler
{
    /** Only content-addressed member derivatives; never the representative's art. */
    public function memberPicturePath(Asset $asset, AssetFile $file): ?string
    {
        $sha = $file->fileObject?->sha256;
        if (! $sha) {
            return null;
        }
        $extension = StackMemberNamingContext::extension(asset: $asset, file: $file);
        $paths = match (true) {
            $extension === 'dng'                                   => [DerivativeImages::dngPreview($sha), DerivativeImages::dngThumbnail($sha)],
            in_array($extension, PreviewKinds::MODEL_VIEWER, true) => [DerivativeImages::modelCapture($sha, (int) $asset->id), DerivativeImages::modelCapture($sha)],
            PreviewKinds::hasEmbeddedThumbnail($extension)         => [DerivativeImages::poster($sha)],
            default                                                => [DerivativeImages::thumbnail($sha, $extension)],
        };
        $disk = Storage::disk(Directory::getAssetDisk());
        foreach ($paths as $path) {
            if (is_string($path) && $disk->exists($path) && $disk->size($path) > 0 && $disk->size($path) <= AiEligibility::MAX_PICTURE_BYTES) {
                return $path;
            }
        }

        return null;
    }

    public function memberPicture(Asset $asset, AssetFile $file): ?string
    {
        $path = $this->memberPicturePath(asset: $asset, file: $file);
        if ($path === null) {
            return null;
        }
        $bytes = (string) Storage::disk(Directory::getAssetDisk())->get($path);

        return AiEligibility::withinBounds($bytes) ? $bytes : null;
    }

    public function sampleFile(Asset $asset, AssetFile $file): MediaSample
    {
        $local = null;
        try {
            $local = app(ContentStore::class)->localPath(object: $file->fileObject, extension: StackMemberNamingContext::extension(asset: $asset, file: $file) ?: null);

            return $this->sampleLocalFile(asset: $asset, file: $file, path: $local->path);
        } catch (Throwable $error) {
            throw new AiBridgeUnavailable('The member content could not be sampled. Retry this row.', previous: $error);
        } finally {
            $local?->release();
        }
    }

    /** The caller owns this local copy; naming reuses it for metadata before releasing it. */
    public function sampleLocalFile(Asset $asset, AssetFile $file, string $path): MediaSample
    {
        $this->transient = null;
        $extension = StackMemberNamingContext::extension(asset: $asset, file: $file);
        $object = $file->fileObject;
        $kind = str_starts_with((string) $object->mime_type, 'video/') ? 'video' : $extension;
        $images = match (true) {
            in_array($extension, AiEligibility::RASTER, true) => $this->rasterFrames($path),
            $kind === 'video'                                 => $this->videoFrames($path),
            $extension === 'pdf'                              => $this->pdfFirstPage($path),
            default                                           => ($picture = $this->memberPicture(asset: $asset, file: $file)) ? array_filter([$this->resize($picture)]) : [],
        };

        return new MediaSample(images: array_values($images), pages: $extension === 'pdf' ? $this->pdfPages($path) : [], kind: $kind, sha256: $object->sha256, transient: $this->transient);
    }

    /** Why the last sample went without a picture when that was the machine's doing; see MediaSample::$transient. */
    private ?string $transient = null;

    public function sample(Asset $asset): MediaSample
    {
        $this->transient = null;
        $sample = $this->read($asset);

        return $sample->images === [] && $this->transient !== null
            ? new MediaSample(images: [], pages: $sample->pages, kind: $sample->kind, sha256: $sample->sha256, transient: $this->transient)
            : $sample;
    }

    /** Runs a tool; a missing program (exit 127) or a timeout is noted as the machine's doing, any other failure is the file's. */
    private function execute(Process $process): void
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->transient ??= 'timeout';

            return;
        }

        if ($process->getExitCode() === 127) {
            $this->transient ??= 'tool_missing';
        }
    }

    private function read(Asset $asset): MediaSample
    {
        $extension = strtolower((string) $asset->extension);
        $type = (string) $asset->file_type;
        $store = app(ContentStore::class);

        try {
            $object = $store->current($asset);
        } catch (ContentUnavailable) {
            return new MediaSample(images: $this->storedPicture($asset, null), pages: [], kind: $type, sha256: null);
        }

        $local = null;

        try {
            $local = $store->localPath($object, $extension ?: null);

            return match (true) {
                in_array($extension, AiEligibility::RASTER, true)  => new MediaSample(images: $this->rasterFrames($local->path) ?: $this->storedPicture($asset, $object->sha256), pages: [], kind: 'image', sha256: $object->sha256),
                $type === 'video'                                  => new MediaSample(images: $this->videoFrames($local->path), pages: [], kind: 'video', sha256: $object->sha256),
                $extension === 'pdf'                               => new MediaSample(images: $this->pdfFirstPage($local->path), pages: $this->pdfPages($local->path), kind: 'pdf', sha256: $object->sha256),
                default                                            => new MediaSample(images: $this->storedPicture($asset, $object->sha256), pages: [], kind: $type ?: $extension, sha256: $object->sha256),
            };
        } catch (Throwable $error) {
            Log::warning('DAM AI index: sampling failed.', ['asset' => $asset->id, 'error' => class_basename($error)]);
            $this->transient ??= 'sampling_error';

            return new MediaSample(images: $this->storedPicture($asset, $object->sha256), pages: [], kind: $type, sha256: $object->sha256);
        } finally {
            $local?->release();
        }
    }

    /**
     * One JPEG of a raster file, at most image_edge on its long side. GD holds about five bytes a pixel and scaling
     * copies the image, so a 37.7 MP camera JPEG ran the 512 MB worker out of memory, an uncatchable fatal that
     * stopped the ai-index worker (2026-10-09, asset 1266). Above gd_max_pixels, or when the size cannot be read,
     * ffmpeg scales it in its own process instead.
     *
     * @return list<string>
     */
    public function rasterFrames(string $path): array
    {
        $size = @getimagesize($path);
        $pixels = is_array($size) ? (int) $size[0] * (int) $size[1] : 0;

        if ($pixels > 0 && $pixels <= (int) config('ai-index.gd_max_pixels')) {
            return array_values(array_filter([$this->resize((string) file_get_contents($path))]));
        }

        $edge = (int) config('ai-index.image_edge');
        $filters = array_filter([
            $this->orientationFilter($path),
            "scale='min({$edge},iw)':'min({$edge},ih)':force_original_aspect_ratio=decrease",
        ]);
        $out = tempnam(sys_get_temp_dir(), 'damaiimg_').'.jpg';

        try {
            // -noautorotate: the EXIF orientation is applied once, by the filter above, whatever this ffmpeg build does.
            $process = new Process(['ffmpeg', '-v', 'error', '-y', '-noautorotate', '-i', $path, '-frames:v', '1', '-vf', implode(',', $filters), '-q:v', '3', $out]);
            $process->setTimeout(60);
            $this->execute($process);

            return $process->isSuccessful() && is_file($out) && filesize($out) > 0 ? [(string) file_get_contents($out)] : [];
        } finally {
            @unlink($out);
            @unlink(substr($out, 0, -4));
        }
    }

    /** The ffmpeg filter that applies a JPEG's EXIF orientation, as Intervention does on the GD path. */
    private function orientationFilter(string $path): ?string
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;

        return match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            2       => 'hflip',
            3       => 'hflip,vflip',
            4       => 'vflip',
            5       => 'transpose=0',
            6       => 'transpose=1',
            7       => 'transpose=3',
            8       => 'transpose=2',
            default => null,
        };
    }

    /**
     * The picture already stored for a file the sampler cannot open itself: the DNG preview, the 3D model capture, or
     * the asset's thumbnail (AiEligibility::storedPicture is the one list, so the UI and this agree).
     *
     * @return list<string>
     */
    private function storedPicture(Asset $asset, ?string $sha256): array
    {
        $path = AiEligibility::storedPicture($asset, $sha256);

        if ($path === null) {
            return [];
        }

        $bytes = (string) Storage::disk(Directory::getAssetDisk())->get($path);

        // The same bounds again where the file is read: whatever was accepted earlier, nothing past them is decoded.
        return AiEligibility::withinBounds($bytes) ? array_values(array_filter([$this->resize($bytes)])) : [];
    }

    private function resize(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        try {
            $edge = (int) config('ai-index.image_edge');

            return (string) (new ImageManager(new Driver))->decode($bytes)->scaleDown(width: $edge, height: $edge)->encode(new JpegEncoder(quality: 85));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function videoFrames(string $path): array
    {
        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path]);
        $probe->setTimeout(30);
        $this->execute($probe);
        $duration = (float) trim($probe->getOutput());
        $frames = [];
        // Without a usable duration every fraction would seek to 0 and return the same frame.
        $fractions = ($probe->isSuccessful() && $duration > 0) ? [0.1, 0.35, 0.6, 0.85] : [0.0];

        foreach ($fractions as $fraction) {
            $out = tempnam(sys_get_temp_dir(), 'damai_').'.jpg';
            $edge = (int) config('ai-index.image_edge');
            $process = new Process(['ffmpeg', '-v', 'error', '-y', '-ss', (string) max(0.0, $duration * $fraction), '-i', $path, '-frames:v', '1', '-vf', "scale='min({$edge},iw)':-2", '-q:v', '4', $out]);
            $process->setTimeout(60);
            $this->execute($process);

            if ($process->isSuccessful() && is_file($out) && filesize($out) > 0) {
                $frames[] = (string) file_get_contents($out);
            }

            @unlink($out);
            @unlink(substr($out, 0, -4));
        }

        return $frames;
    }

    /** @return list<string> */
    private function pdfFirstPage(string $path): array
    {
        $prefix = tempnam(sys_get_temp_dir(), 'damaipdf_');
        $process = new Process(['pdftoppm', '-jpeg', '-f', '1', '-l', '1', '-scale-to', (string) config('ai-index.image_edge'), '-singlefile', $path, $prefix]);
        $process->setTimeout(60);
        $this->execute($process);
        $bytes = is_file($prefix.'.jpg') ? (string) file_get_contents($prefix.'.jpg') : '';
        @unlink($prefix.'.jpg');
        @unlink($prefix);

        return $bytes !== '' ? [$bytes] : [];
    }

    /** @return list<string> */
    private function pdfPages(string $path): array
    {
        $process = new Process(['pdftotext', '-layout', '-f', '1', '-l', (string) config('ai-index.max_pdf_pages'), $path, '-']);
        $process->setTimeout(120);
        $this->execute($process);

        if (! $process->isSuccessful()) {
            return [];
        }

        $pages = explode("\f", $process->getOutput());

        return array_values(array_map(
            fn (string $page) => mb_substr(trim(preg_replace('/\s+/u', ' ', $page)), 0, 2000),
            array_slice($pages, 0, (int) config('ai-index.max_pdf_pages'))
        ));
    }
}
