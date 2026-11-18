<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: an MP4 every browser plays: H.264 High, yuv420p, AAC, moov atom first (faststart), capped in height. */
class WebMp4Recipe extends BaseRecipe
{
    public function key(): string
    {
        return 'web_mp4';
    }

    public function fileTypes(): array
    {
        return ['video'];
    }

    public function parameters(): array
    {
        return [
            'max_height' => ['type' => 'enum', 'options' => ['source', '2160', '1080', '720', '480'], 'default' => '1080'],
        ];
    }

    public function label(array $params): string
    {
        return $params['max_height'] === 'source'
            ? trans('dam::app.admin.batch.labels.web-mp4-source')
            : trans('dam::app.admin.batch.labels.web-mp4', ['height' => $params['max_height']]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        $output = $run->output('mp4');
        $height = $run->params['max_height'];
        // -2 evens only the width; a source shorter than the cap keeps its own (possibly odd) height, which yuv420p rejects.
        $filter = $height === 'source' ? null : "scale=-2:'min(".(int) $height.",ih)',scale=trunc(iw/2)*2:trunc(ih/2)*2";

        $this->ffmpeg($run, ['-i', $run->input, ...self::h264(crf: 23, filter: $filter), '-profile:v', 'high', $output]);

        return $this->finished($output, 'mp4');
    }
}
