<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: an animated GIF of part of a video, through ffmpeg's palettegen/paletteuse for a per-clip palette. */
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

    public function parameters(): array
    {
        return [
            'start'    => ['type' => 'number', 'min' => 0, 'max' => 86400, 'default' => 0, 'unit' => 's'],
            'duration' => ['type' => 'number', 'min' => 0.1, 'max' => 30, 'default' => 3, 'unit' => 's'],
            'fps'      => ['type' => 'number', 'integer' => true, 'min' => 1, 'max' => 30, 'default' => 12],
            'width'    => ['type' => 'number', 'integer' => true, 'min' => 32, 'max' => 1280, 'default' => 480, 'unit' => 'px'],
        ];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.gif', [
            'start' => self::clock((float) $params['start']), 'end' => self::clock((float) $params['start'] + (float) $params['duration']),
        ]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $start = (float) $run->params['start'];
        $this->requireVideoAt($run, $start);
        $output = $run->output('gif');
        $graph = 'fps='.(int) $run->params['fps'].",scale='min(".(int) $run->params['width'].",iw)':-1:flags=lanczos,split[a][b];"
            .'[a]palettegen=stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=4';

        $this->ffmpeg($run, [
            '-ss', self::num($start), '-t', self::num((float) $run->params['duration']), '-i', $run->input,
            '-filter_complex', $graph, '-an', '-loop', '0', $output,
        ]);

        return $this->finished($output, 'gif');
    }
}
