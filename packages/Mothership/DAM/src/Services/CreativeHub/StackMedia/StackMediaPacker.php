<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * A reader whose app reads no media by path, so Open with serves a copy of the file holding the media instead (a
 * VectorCraft document's linked images), of the file's own type: the file rewritten up to the media, then each medium's
 * bytes in order. The copy is streamed, so no medium is held in memory.
 */
interface StackMediaPacker extends StackMediaReader
{
    /**
     * The copy of `$contents` holding each named medium whose byte length `$sizes` gives (keyed by the path paths()
     * returned): its bytes before the media, and the paths whose bytes follow, in order. Null when the file cannot be
     * read or holds none of them, so the original is served.
     *
     * @param  array<string, int>  $sizes
     * @return array{head: string, append: list<string>}|null
     */
    public function pack(string $contents, array $sizes): ?array;
}
