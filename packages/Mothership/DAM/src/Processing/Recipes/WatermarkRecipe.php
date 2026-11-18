<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/**
 * D27: lays another Library image over an image or a video, in a corner or the centre, scaled to a share of the
 * frame's width, at an opacity. A video can be trimmed in the same pass (start/end), which is how the viewer's
 * "Clip & watermark" runs as one output. The batch service resolves the overlay to the exact revision the
 * requester could see when the batch started and checks it again when the item runs.
 */
class WatermarkRecipe extends BaseRecipe
{
    /** Formats an image keeps when watermarked; anything else is written as PNG. */
    private const IMAGE_KEEP = ['jpg', 'jpeg', 'png', 'webp'];

    /** Overlay x:y per position, margin $m; ffmpeg's overlay names the frame W/H and the overlay w/h. */
    private const POSITIONS = [
        'top-left'     => ['%1$s', '%1$s'],
        'top-right'    => ['W-w-%1$s', '%1$s'],
        'bottom-left'  => ['%1$s', 'H-h-%1$s'],
        'bottom-right' => ['W-w-%1$s', 'H-h-%1$s'],
        'center'       => ['(W-w)/2', '(H-h)/2'],
    ];

    public function key(): string
    {
        return 'watermark';
    }

    public function fileTypes(): array
    {
        return ['image', 'video'];
    }

    public function parameters(): array
    {
        return [
            'overlay_asset_id' => ['type' => 'asset', 'required' => true],
            'position'         => ['type' => 'enum', 'options' => array_keys(self::POSITIONS), 'default' => 'bottom-right'],
            'scale'            => ['type' => 'number', 'min' => 0.05, 'max' => 1, 'default' => 0.2],
            'opacity'          => ['type' => 'number', 'min' => 0.05, 'max' => 1, 'default' => 0.8],
            // Video only: trim to start–end in the same pass. Both or neither.
            'start'            => ['type' => 'number', 'min' => 0, 'max' => 86400, 'unit' => 's'],
            'end'              => ['type' => 'number', 'min' => 0.1, 'max' => 86400, 'unit' => 's'],
        ];
    }

    public function check(array $params): array
    {
        $start = $params['start'] ?? null;
        $end = $params['end'] ?? null;

        if (($start === null) !== ($end === null)) {
            return [($start === null ? 'start' : 'end') => 'dam::app.admin.batch.validation.both-or-neither'];
        }

        return $start !== null && (float) $end <= (float) $start ? ['end' => 'dam::app.admin.batch.validation.end-after-start'] : [];
    }

    public function label(array $params): string
    {
        return ($params['start'] ?? null) === null
            ? trans('dam::app.admin.batch.labels.watermark')
            : trans('dam::app.admin.batch.labels.watermark-clip', ['start' => self::clock((float) $params['start']), 'end' => self::clock((float) $params['end'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        if ($run->overlay === null) {
            throw new RecipeFailed(RecipeFailed::UNSUPPORTED_INPUT);
        }

        $video = $run->fileType === 'video';
        $start = $run->params['start'] ?? null;
        $info = $run->info();

        if (! $info->hasVideo || ! $info->width) {
            throw new RecipeFailed(RecipeFailed::UNSUPPORTED_INPUT);
        }

        if ($video && $start !== null) {
            $this->requireVideoAt($run, (float) $start);
        }

        // The overlay's width as a share of the frame's, kept even; the margin is 3% of the frame's width.
        $overlayWidth = max(2, 2 * (int) round($info->width * (float) $run->params['scale'] / 2));
        $margin = (string) max(0, (int) round($info->width * 0.03));
        [$x, $y] = array_map(fn (string $axis) => sprintf($axis, $margin), self::POSITIONS[$run->params['position']]);
        $graph = '[1:v]scale='.$overlayWidth.':-1,format=rgba,colorchannelmixer=aa='.self::num((float) $run->params['opacity'], 2).'[wm];'
            .'[0:v][wm]overlay=x='.$x.':y='.$y.':format=auto'.($video ? ',format=yuv420p,scale=trunc(iw/2)*2:trunc(ih/2)*2' : '').'[out]';
        $window = $video && $start !== null ? ['-ss', self::num((float) $start), '-t', self::num((float) $run->params['end'] - (float) $start)] : [];

        if ($video) {
            $output = $run->output('mp4');
            $this->ffmpeg($run, [
                ...$window, '-i', $run->input, '-i', $run->overlay, '-filter_complex', $graph,
                '-map', '[out]', '-map', '0:a:0?', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
                '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', $output,
            ]);

            return $this->finished($output, 'mp4');
        }

        $source = strtolower($run->inputExtension) === 'jpeg' ? 'jpg' : strtolower($run->inputExtension);
        $format = in_array($source, self::IMAGE_KEEP, true) ? $source : 'png';
        $output = $run->output($format);
        $this->ffmpeg($run, [
            '-i', $run->input, '-i', $run->overlay, '-filter_complex', $graph, '-map', '[out]', '-frames:v', '1',
            ...($format === 'jpg' ? ['-q:v', '2'] : []), ...($format === 'webp' ? ['-quality', '90'] : []), $output,
        ]);

        return $this->finished($output, $format);
    }
}
