<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** D28: a video's (or audio file's) first audio stream, as AAC in M4A or as MP3. */
class AudioRecipe extends BaseRecipe
{
    public function key(): string
    {
        return 'audio';
    }

    public function fileTypes(): array
    {
        return ['video', 'audio'];
    }

    public function parameters(): array
    {
        return [
            'format' => ['type' => 'enum', 'options' => ['m4a', 'mp3'], 'default' => 'm4a'],
        ];
    }

    public function label(array $params): string
    {
        return trans('dam::app.admin.batch.labels.audio', ['format' => strtoupper((string) $params['format'])]);
    }

    public function run(RecipeRun $run): RecipeOutput
    {
        if (! $run->info()->hasAudio) {
            throw new RecipeFailed(RecipeFailed::NO_AUDIO);
        }

        $format = (string) $run->params['format'];
        $output = $run->output($format);
        $codec = $format === 'mp3' ? ['-c:a', 'libmp3lame', '-q:a', '2'] : ['-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart'];

        $this->ffmpeg($run, ['-i', $run->input, '-vn', '-map', '0:a:0', ...$codec, $output]);

        return $this->finished($output, $format);
    }
}
