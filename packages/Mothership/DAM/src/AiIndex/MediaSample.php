<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

/** What the captioner and embedder see of one asset: JPEG frames and PDF page texts. */
final class MediaSample
{
    /**
     * @param  list<string>  $images  JPEG bytes, long edge ≤ ai-index.image_edge
     * @param  list<string>  $pages  PDF text per page, page 1 first
     * @param  string|null  $transient  why no picture was made when that is down to the machine (tool missing, timeout, read error), not the file
     */
    public function __construct(
        public readonly array $images,
        public readonly array $pages,
        public readonly string $kind,
        public readonly ?string $sha256,
        public readonly ?string $transient = null,
    ) {}
}
