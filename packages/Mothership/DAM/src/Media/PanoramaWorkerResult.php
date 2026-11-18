<?php

namespace Mothership\DAM\Media;

/**
 * The successful outcome of a PanoramaWorker::run() call: a local path to
 * the produced file, its declared MIME type, and worker-reported technical
 * metadata (width/height/codec_name/pix_fmt/spherical/etc) used by
 * RenditionPublisher to validate the result before it is ever published.
 *
 * $path is a local filesystem path inside the worker's attempt-specific
 * working directory. This class itself never deletes anything — for who
 * is responsible for that directory's cleanup and when, see
 * PanoramaWorker's docblock (the authoritative statement of that
 * contract; it is intentionally not duplicated, and possibly diverged,
 * here).
 */
final readonly class PanoramaWorkerResult
{
    public function __construct(
        public string $path,
        public string $mime,
        public array $technical,
    ) {}
}
