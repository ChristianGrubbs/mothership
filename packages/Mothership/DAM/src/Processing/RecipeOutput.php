<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

/** A finished recipe output in the work directory, and anything about how it was made worth keeping in lineage. */
final class RecipeOutput
{
    /** @param  array<string, scalar>  $notes  e.g. the clip recipe's `copy` or `reencode` decision */
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly array $notes = [],
    ) {}
}
