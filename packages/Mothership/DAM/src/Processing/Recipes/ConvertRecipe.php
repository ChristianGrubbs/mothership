<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D17: an image to JPG, PNG, WebP or AVIF (libvips), a video to MP4 (H.264/AAC) or WebM (VP9/Opus). */
class ConvertRecipe extends BaseRecipe
{
    private const VIDEO_FORMATS = ['mp4', 'webm'];

    public function key(): string
    {
        return 'convert';
    }

    public function fileTypes(): array
    {
        return ['image', 'video'];
    }

    public function accepts(string $fileType, array $params): bool
    {
        return match ($fileType) {
            'image' => in_array($params['format'] ?? null, self::IMAGE_FORMATS, true),
            'video' => in_array($params['format'] ?? null, self::VIDEO_FORMATS, true),
            default => false,
        };
    }

    public function parameters(): array
    {
        return [
            'format'  => ['type' => 'enum', 'options' => [...self::IMAGE_FORMATS, ...self::VIDEO_FORMATS], 'required' => true],
            'quality' => ['type' => 'number', 'integer' => true, 'min' => 1, 'max' => 100, 'default' => 85],
        ];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.convert', ['format' => strtoupper((string) $params['format'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $format = (string) $run->params['format'];
        $quality = (int) $run->params['quality'];
        $output = $run->output($format);

        if ($run->fileType === 'image') {
            // JPEG has no alpha: flatten onto white first, rather than letting the encoder drop it to black.
            $source = $run->input;

            if ($format === 'jpg') {
                $source = $run->scratch('flat.v');
                $this->vips($run, ['flatten', $run->input, $source, '--background', '255']);
            }

            $options = $format === 'png' ? '[compression=6]' : '[Q='.$quality.']';
            $this->vips($run, ['copy', $source, $output.$options]);

            return $this->finished($output, $format);
        }

        $this->ffmpeg($run, ['-i', $run->input, ...($format === 'webm' ? self::vp9(self::crf($quality)) : self::h264(self::crf($quality))), $output]);

        return $this->finished($output, $format);
    }
}
