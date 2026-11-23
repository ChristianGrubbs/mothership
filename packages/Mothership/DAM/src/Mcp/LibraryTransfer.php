<?php

namespace Mothership\DAM\Mcp;

/**
 * The bytes library_fetch or library_vectorization (with format) copies out, for a caller that writes the copy itself:
 * the REST route streams them to the launcher, which checks them against sha256 and size and picks the local name.
 * result is the tool result with path, name and reused still null, for whoever writes the copy to fill in.
 */
final class LibraryTransfer
{
    /**
     * @param  resource  $stream  open for reading at the first byte
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public readonly mixed $stream,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $name,
        public readonly array $result,
    ) {}
}
