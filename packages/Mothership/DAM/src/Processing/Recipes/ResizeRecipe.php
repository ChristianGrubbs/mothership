<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/**
 * D17: fit inside, or cover, a width × height box. Images go through libvips (`thumbnail`, which shrinks on load
 * and never enlarges), keeping their format or taking JPG, PNG or WebP; videos go through ffmpeg to MP4, where only
 * cover enlarges a frame smaller than the box, to fill it.
 */
class ResizeRecipe extends BaseRecipe
{
    /** Formats a resized image keeps; anything else (TIFF, HEIC, PSD …) is written as PNG unless a format is chosen. */
    private const KEEPABLE = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    public function key(): string
    {
        return 'resize';
    }

    public function fileTypes(): array
    {
        return ['image', 'video'];
    }

    public function parameters(): array
    {
        return [
            'width'  => ['type' => 'number', 'integer' => true, 'min' => 16, 'max' => 16384, 'unit' => 'px'],
            'height' => ['type' => 'number', 'integer' => true, 'min' => 16, 'max' => 16384, 'unit' => 'px'],
            'fit'    => ['type' => 'enum', 'options' => ['fit', 'cover'], 'default' => 'fit'],
            'format' => ['type' => 'enum', 'options' => ['keep', 'jpg', 'png', 'webp'], 'default' => 'keep'],
        ];
    }

    public function check(array $params): array
    {
        if (($params['width'] ?? null) === null && ($params['height'] ?? null) === null) {
            return ['width' => 'dam::app.admin.batch.validation.size-required'];
        }

        if ($params['fit'] === 'cover' && (($params['width'] ?? null) === null || ($params['height'] ?? null) === null)) {
            return ['height' => 'dam::app.admin.batch.validation.cover-needs-both'];
        }

        return [];
    }

    public function label(array $params): string
    {
        $width = $params['width'] ?? null;
        $height = $params['height'] ?? null;

        return match (true) {
            $width !== null && $height !== null => trans('dam::app.admin.batch.labels.resize-box', ['width' => $width, 'height' => $height]),
            $width !== null                     => trans('dam::app.admin.batch.labels.resize-width', ['width' => $width]),
            default                             => trans('dam::app.admin.batch.labels.resize-height', ['height' => $height]),
        };
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $width = $run->params['width'] ?? null;
        $height = $run->params['height'] ?? null;
        $cover = $run->params['fit'] === 'cover';

        if ($run->fileType === 'video') {
            $output = $run->output('mp4');
            $filter = $this->videoScale(width: $width, height: $height, cover: $cover);
            $this->ffmpeg($run, ['-i', $run->input, ...self::h264(crf: 20, filter: $filter), $output]);

            return $this->finished($output, 'mp4');
        }

        $format = $this->imageFormat(chosen: (string) $run->params['format'], source: $run->inputExtension);
        $output = $run->output($format);
        $target = $format === 'jpg' ? $run->scratch('resized.v') : $output.($format === 'png' ? '' : '[Q=90]');
        // thumbnail's width is required; a height-only box gets a width no image reaches.
        $arguments = ['thumbnail', $run->input, $target, (string) ($width ?? 100000), '--size', 'down'];

        if ($height !== null) {
            array_push($arguments, '--height', (string) $height);
        }

        if ($cover) {
            array_push($arguments, '--crop', 'centre');
        }

        $this->vips($run, $arguments);

        if ($format === 'jpg') {
            $this->vips($run, ['flatten', $target, $output.'[Q=90]', '--background', '255']);
        }

        return $this->finished($output, $format);
    }

    private function imageFormat(string $chosen, string $source): string
    {
        if ($chosen !== 'keep') {
            return $chosen;
        }

        $source = strtolower($source) === 'jpeg' ? 'jpg' : strtolower($source);

        return in_array($source, self::KEEPABLE, true) ? $source : 'png';
    }

    /** An ffmpeg scale for the box: fit keeps all of the frame, cover fills the box and crops the overflow. */
    private function videoScale(?int $width, ?int $height, bool $cover): string
    {
        if ($width === null || $height === null) {
            // One side given: the other follows the aspect ratio, never enlarged. Both sides end even for yuv420p: -2 only
            // evens the derived side, and the given (or the source's own) side can be odd.
            return ($width !== null
                ? "scale='min(".$width.",iw)':-2"
                : "scale=-2:'min(".$height.",ih)'").',scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }

        return $cover
            ? 'scale=w='.$width.':h='.$height.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.',scale=trunc(iw/2)*2:trunc(ih/2)*2'
            : "scale=w='min(".$width.",iw)':h='min(".$height.",ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2";
    }
}
