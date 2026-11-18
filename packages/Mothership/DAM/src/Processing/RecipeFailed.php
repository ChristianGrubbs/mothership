<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use RuntimeException;

/**
 * A recipe could not make its output. The message is a short, user-safe code (dam::app.admin.batch.errors.*)
 * that the item row stores and the UI translates; the tool's own output never leaves the worker.
 */
final class RecipeFailed extends RuntimeException
{
    public const TOOL_FAILED = 'tool_failed';

    public const TIMEOUT = 'timeout';

    public const UNSUPPORTED_INPUT = 'unsupported_input';

    public const OUT_OF_RANGE = 'out_of_range';

    public const NO_AUDIO = 'no_audio_stream';

    public const NO_VIDEO = 'no_video_stream';

    public const EMPTY_OUTPUT = 'empty_output';

    public function __construct(string $code, public readonly ?int $exitCode = null)
    {
        parent::__construct($code);
    }

    public function code(): string
    {
        return $this->getMessage();
    }
}
