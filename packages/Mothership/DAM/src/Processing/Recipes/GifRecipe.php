<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: an animated GIF of a video or an explicit trim, with a palette taken from the exported frames. */
class GifRecipe extends BaseRecipe
{
    public function key(): string
    {
        return 'gif';
    }

    public function fileTypes(): array
    {
        return ['video'];
    }

    public function version(): int
    {
        return 2;
    }

    public function parameters(): array
    {
        return [
            'start'    => ['type' => 'number', 'min' => 0, 'max' => 86400, 'default' => 0, 'unit' => 's'],
            'duration' => ['type' => 'number', 'min' => 0.1, 'max' => 30, 'placeholder' => 'dam::app.admin.batch.params.video-end', 'unit' => 's'],
            'fps'      => ['type' => 'number', 'integer' => true, 'min' => 1, 'max' => 30, 'default' => 24],
            'width'    => ['type' => 'number', 'integer' => true, 'min' => 32, 'max' => 1280, 'default' => 480, 'unit' => 'px'],
        ];
    }

    public function label(array $params): string
    {
        $start = (float) $params['start'];
        if (($params['duration'] ?? null) === null) {
            return trans('dam::app.admin.batch.labels.gif-to-end', ['start' => self::clock($start)]);
        }

        return trans('dam::app.admin.batch.labels.gif', ['start' => self::clock($start), 'end' => self::clock($start + (float) $params['duration'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $start = (float) $run->params['start'];
        $this->requireVideoAt($run, $start);
        $window = ['-ss', self::num($start)];
        if (($run->params['duration'] ?? null) !== null) {
            array_push($window, '-t', self::num((float) $run->params['duration']));
        }
        array_push($window, '-i', $run->input);

        // Generate the palette first so the encoding pass can stream frames instead of buffering the whole video.
        $frames = 'fps='.(int) $run->params['fps'].",scale='min(".(int) $run->params['width'].",iw)':-1:flags=lanczos";
        $palette = $run->scratch('palette.png');
        $this->ffmpeg($run, [...$window, '-map', '0:v:0', '-vf', $frames.',palettegen=stats_mode=diff', '-frames:v', '1', '-update', '1', $palette]);

        $output = $run->output('gif');
        $this->ffmpeg($run, [
            ...$window, '-i', $palette,
            '-filter_complex', '[0:v:0]'.$frames.'[frames];[frames][1:v:0]paletteuse=dither=bayer:bayer_scale=4',
            '-an', '-loop', '0', $output,
        ]);

        return $this->finished($output, 'gif');
    }
}
