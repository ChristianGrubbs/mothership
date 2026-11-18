<?php

namespace Mothership\DAM\Media;

use Closure;
use Mothership\DAM\Models\Rendition;

/**
 * The conversion boundary GeneratePanoramaPreview drives. A single call to
 * run() must:
 *
 *  - report cooperative progress via $progress(int $percent, string $stage),
 *    called zero or more times with values the caller (the job) clamps to a
 *    monotonically-increasing range — the worker itself does not need to
 *    guarantee monotonicity;
 *  - poll $cancelled(): bool between steps and throw PanoramaCancelled as
 *    soon as it reports true, rather than completing the conversion;
 *  - either return a PanoramaWorkerResult describing the produced file, or
 *    throw (PanoramaCancelled for cancellation, any other Throwable for a
 *    failed conversion).
 *
 * Implementations must never publish anything themselves — RenditionPublisher
 * owns validation and publication of the result this method returns.
 *
 * WORKING-DIRECTORY / CLEANUP CONTRACT (authoritative — this is the one
 * place this rule is stated; do not restate a different version of it
 * elsewhere). If an implementation creates a working directory (to stage
 * a source file, receive subprocess output, etc.), ownership of removing
 * it is split by outcome, because PanoramaWorkerResult carries only a
 * single file path — never a directory handle — so the caller has
 * nothing to act on except on the one path that returns successfully:
 *
 *  - On a THROW (PanoramaCancelled or any other Throwable): the caller
 *    receives nothing to clean up. The implementation MUST remove its own
 *    working directory, recursively, before the exception leaves run().
 *    Leaving anything behind on this path is a permanent leak — nothing
 *    else in the system knows the directory ever existed.
 *  - On a SUCCESSFUL return: the implementation MUST NOT touch its
 *    working directory afterward. GeneratePanoramaPreview removes it,
 *    recursively, only after RenditionPublisher::publish() has finished
 *    copying PanoramaWorkerResult::$path into permanent storage — an
 *    implementation that deletes early risks racing a slow publish.
 *
 * "Recursively" matters: a real conversion process can leave more than
 * one file behind (temp segments, multi-pass logs, a partial output) even
 * on success, so removing only the one file the result points at and
 * leaving the rest is still a leak.
 */
interface PanoramaWorker
{
    public function run(Rendition $rendition, Closure $progress, Closure $cancelled): PanoramaWorkerResult;
}
