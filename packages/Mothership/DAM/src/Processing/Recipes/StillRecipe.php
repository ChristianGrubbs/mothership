<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: the frame at time t of a video, as PNG or JPG. */
class StillRecipe extends BaseRecipe
{
    public function key(): string
    {
        return 'still';
    }

    public function fileTypes(): array
    {
        return ['video'];
    }

    public function parameters(): array
    {
        return [
            'time'   => ['type' => 'number', 'min' => 0, 'max' => 86400, 'default' => 0, 'unit' => 's'],
            'format' => ['type' => 'enum', 'options' => ['png', 'jpg'], 'default' => 'png'],
        ];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.still', ['time' => self::clock((float) $params['time'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $time = (float) $run->params['time'];
        $this->requireVideoAt($run, $time);
        $format = (string) $run->params['format'];
        $output = $run->output($format);

        $this->ffmpeg($run, [
            '-ss', self::num($time), '-i', $run->input, '-map', '0:v:0', '-frames:v', '1',
            ...($format === 'jpg' ? ['-q:v', '2'] : []), $output,
        ]);

        return $this->finished($output, $format);
    }
}
