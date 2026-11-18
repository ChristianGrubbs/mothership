<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

/**
 * One recipe run over one staged source: the input path (a read-only symlink or copy, never written), the item's
 * own work directory under storage, the validated parameters, and the staged watermark overlay when the recipe
 * takes one. Outputs go to output(), inside the work directory.
 */
final class RecipeRun
{
    private ?MediaInfo $info = null;

    /** @param  array<string, mixed>  $params */
    public function __construct(
        public readonly string $input,
        public readonly string $inputExtension,
        public readonly string $fileType,
        public readonly array $params,
        public readonly string $workdir,
        public readonly MediaTools $tools,
        public readonly ?string $overlay = null,
    ) {}

    public function output(string $extension): string
    {
        return $this->workdir.'/output.'.$extension;
    }

    /** A scratch file in the work directory, for a recipe with an intermediate step. */
    public function scratch(string $name): string
    {
        return $this->workdir.'/'.$name;
    }

    public function info(): MediaInfo
    {
        return $this->info ??= $this->tools->probe($this->input, $this->workdir);
    }
}
