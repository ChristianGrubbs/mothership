<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: a PNG of the audio's waveform (ffmpeg showwavespic), mixed to one channel. */
class WaveformRecipe extends BaseRecipe
{
    /** Colours by name, so no free text reaches the filter graph. */
    private const COLOURS = ['blue' => '0x2563eb', 'black' => '0x111827', 'gray' => '0x6b7280', 'white' => '0xffffff'];

    public function key(): string
    {
        return 'waveform';
    }

    public function fileTypes(): array
    {
        return ['video', 'audio'];
    }

    public function parameters(): array
    {
        return [
            'width'  => ['type' => 'number', 'integer' => true, 'min' => 200, 'max' => 4000, 'default' => 1800, 'unit' => 'px'],
            'height' => ['type' => 'number', 'integer' => true, 'min' => 60, 'max' => 1000, 'default' => 280, 'unit' => 'px'],
            'color'  => ['type' => 'enum', 'options' => array_keys(self::COLOURS), 'default' => 'blue'],
        ];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.waveform', ['width' => $params['width'], 'height' => $params['height']]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        if (! $run->info()->hasAudio) {
            throw new RecipeFailed(RecipeFailed::NO_AUDIO);
        }

        $output = $run->output('png');
        $graph = '[0:a:0]aformat=channel_layouts=mono,showwavespic=s='.(int) $run->params['width'].'x'.(int) $run->params['height']
            .':colors='.self::COLOURS[$run->params['color']];

        $this->ffmpeg($run, ['-i', $run->input, '-filter_complex', $graph, '-frames:v', '1', $output]);

        return $this->finished($output, 'png');
    }
}
