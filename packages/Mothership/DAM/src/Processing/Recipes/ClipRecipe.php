<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/**
 * D27: start to end of a video. Stream copy when that is exact: the start lands on a keyframe (within half a
 * frame), so the copy starts on the very frame asked for, with no generation loss and in a fraction of the time.
 * Otherwise a copy would start on the keyframe before the start (frames nobody asked for, or a frozen lead-in),
 * so it re-encodes to H.264/AAC MP4 (VP9/Opus for a WebM source). `reencode` forces the re-encode.
 */
class ClipRecipe extends BaseRecipe
{
    /** Containers a stream copy keeps; anything else is re-encoded to MP4. */
    private const COPYABLE = ['mp4', 'mov', 'm4v', 'mkv', 'webm'];

    public function key(): string
    {
        return 'clip';
    }

    public function fileTypes(): array
    {
        return ['video'];
    }

    public function parameters(): array
    {
        return [
            'start' => ['type' => 'number', 'min' => 0, 'max' => 86400, 'required' => true, 'unit' => 's'],
            'end'   => ['type' => 'number', 'min' => 0.1, 'max' => 86400, 'required' => true, 'unit' => 's'],
            'mode'  => ['type' => 'enum', 'options' => ['auto', 'reencode'], 'default' => 'auto'],
        ];
    }

    public function check(array $params): array
    {
        return (float) $params['end'] > (float) $params['start'] ? [] : ['end' => 'dam::app.admin.batch.validation.end-after-start'];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.clip', ['start' => self::clock((float) $params['start']), 'end' => self::clock((float) $params['end'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $start = (float) $run->params['start'];
        $this->requireVideoAt($run, $start);
        $info = $run->info();
        $end = $info->duration !== null ? min((float) $run->params['end'], $info->duration) : (float) $run->params['end'];
        $source = strtolower($run->inputExtension);
        $copy = $run->params['mode'] === 'auto' && in_array($source, self::COPYABLE, true) && $this->keyframeAt(run: $run, at: $start);
        $extension = $copy ? $source : ($source === 'webm' ? 'webm' : 'mp4');
        $output = $run->output($extension);
        $window = ['-ss', self::num($start), '-t', self::num($end - $start), '-i', $run->input];

        if ($copy) {
            // Exact at the start (a keyframe); the end is cut at a packet, so it can run a frame or two long by the
            // codec's reordering delay. The muxer's default timestamp handling starts both streams at zero.
            $this->ffmpeg($run, [...$window, '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy', $output]);
        } else {
            $this->ffmpeg($run, [...$window, ...($extension === 'webm' ? self::vp9(crf: 20) : self::h264(crf: 20)), $output]);
        }

        return $this->finished($output, $extension, ['clip_mode' => $copy ? 'copy' : 'reencode']);
    }

    /** Whether a keyframe sits within half a frame of $at (relative to the stream's own start time). */
    private function keyframeAt(RecipeRun $run, float $at): bool
    {
        $info = $run->info();
        $tolerance = max(0.001, $info->fps ? 0.5 / $info->fps : 0.02);
        $target = $at + $info->startTime;

        foreach ($run->tools->keyframes(path: $run->input, from: $target, span: 1.0, workdir: $run->workdir) as $time) {
            if (abs($time - $target) <= $tolerance) {
                return true;
            }
        }

        return false;
    }
}
