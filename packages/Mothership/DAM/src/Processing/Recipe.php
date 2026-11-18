<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

/**
 * A named, versioned way to make a derived file from an asset's content (roadmap §3.5): convert, resize, gif,
 * still, audio, waveform, web_mp4, clip, watermark. RecipeRegistry validates parameters against parameters()
 * and check(); the batch runner calls run() once per item and publishes what it returns with lineage.
 */
interface Recipe
{
    public function key(): string;

    /** Bumped when the output for the same parameters changes, so the idempotency fingerprint changes too. */
    public function version(): int;

    /** @return list<string> the asset file types (image, video, audio) the recipe can take at all */
    public function fileTypes(): array;

    /** Whether an asset of this type can take the recipe with these parameters (convert to webp: images only). */
    public function accepts(string $fileType, array $params): bool;

    /**
     * Parameter schema, shared by validation, the UI and the REST description. Each entry has a `type`:
     * `number` (min, max, integer, default, required), `enum` (options, default) or `asset` (an asset id).
     *
     * @return array<string, array<string, mixed>>
     */
    public function parameters(): array;

    /**
     * Rules across parameters, after each passed its own (end after start). Field => message key.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function check(array $params): array;

    /** The role the output takes when it is attached to its source entry. */
    public function outputRole(): string;

    /** The output's label, without an extension: "GIF 0:05–0:08". */
    public function label(array $params): string;

    /** @throws RecipeFailed */
    public function run(RecipeRun $run): RecipeOutput;
}
