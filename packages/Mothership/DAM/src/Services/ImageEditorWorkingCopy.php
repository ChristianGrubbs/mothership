<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Process;
use Mothership\DAM\Support\LocalFile;
use Throwable;

/** Bounded editing input; original blobs and schema/render limits stay unchanged. */
class ImageEditorWorkingCopy
{
    public function prepare(string $path): array
    {
        $info = @getimagesize($path);
        abort_unless($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true), 422, 'The editor supports PNG, JPEG or WebP images.');
        [$width, $height] = $info;
        abort_unless($width > 0 && $height > 0, 422, 'Image dimensions must be positive.');
        $scale = min(1, 8192 / $width, 8192 / $height, sqrt(32000000 / ($width * $height)));
        // PHP reads JPEG orientation without decoding its pixels; ordinary untagged bounded inputs
        // retain the established GD path. Every non-identity JPEG orientation gets baked into a PNG.
        $exif = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (@exif_read_data($path) ?: []) : [];
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $orientation = in_array($orientation, range(1, 8), true) ? $orientation : 1;
        $details = ['original_width' => $width, 'original_height' => $height, 'working_width' => $width, 'working_height' => $height, 'reduced' => $scale < 1];
        if ($scale === 1 && $orientation === 1) {
            $decode = match ($info[2]) {
                IMAGETYPE_PNG  => 'imagecreatefrompng',
                IMAGETYPE_JPEG => 'imagecreatefromjpeg',
                IMAGETYPE_WEBP => 'imagecreatefromwebp',
            };
            $image = @$decode($path);
            abort_unless($image, 422, 'Image could not be decoded.');
            imagedestroy($image);

            return ['path' => $path, 'info' => $info, 'details' => $details, 'temporary' => null];
        }

        $directory = sys_get_temp_dir().'/dam-editor-'.bin2hex(random_bytes(8));
        abort_unless(@mkdir($directory, 0700), 422, 'The image working copy could not be staged.');
        // Lossless PNG retains transparency and avoids an additional JPEG/WebP generation loss.
        $temporary = new LocalFile(path: $directory.'/working.png', directory: $directory);
        try {
            // Match browsers' EXIF display orientation, then bake it into the PNG. The bounding box must
            // follow the oriented axes; otherwise a portrait EXIF image is reduced a second time to fit a landscape box.
            $header = Process::timeout(30)->run(['vipsheader', '-a', $path]);
            abort_unless($header->successful(), 422, 'The image working copy could not be generated.');
            preg_match('/^orientation: ([1-8])\s*$/m', $header->output(), $headerOrientation);
            $swapped = (int) ($headerOrientation[1] ?? $orientation) >= 5;
            if ($swapped) {
                $details['original_width'] = $height;
                $details['original_height'] = $width;
            }
            $targetWidth = max(1, (int) floor(($swapped ? $height : $width) * $scale));
            $targetHeight = max(1, (int) floor(($swapped ? $width : $height) * $scale));
            ImageEditorLog::write(event: 'working_copy_selected', ctx: array_replace($details, ['working_width' => $targetWidth, 'working_height' => $targetHeight]));
            $result = Process::timeout(120)->env(['VIPS_CONCURRENCY' => '2'])->run([
                'vips', 'thumbnail', $path, $temporary->path, (string) $targetWidth,
                '--height', (string) $targetHeight, '--size', 'down', '--fail-on', 'warning',
            ]);
            $output = @getimagesize($temporary->path);
            abort_unless($result->successful() && $output && $output[2] === IMAGETYPE_PNG
                && $output[0] <= $targetWidth && $output[1] <= $targetHeight
                && $output[0] * $output[1] <= 32000000, 422, 'The image working copy could not be generated.');
            $details['working_width'] = $output[0];
            $details['working_height'] = $output[1];
            ImageEditorLog::write(event: 'working_copy_generated', ctx: $details);

            return ['path' => $temporary->path, 'info' => $output, 'details' => $details, 'temporary' => $temporary];
        } catch (Throwable $error) {
            $temporary->release();
            ImageEditorLog::write(event: 'working_copy_failed', ctx: ['error_type' => $error::class]);
            abort(422, 'The image working copy could not be generated.');
        }
    }
}
