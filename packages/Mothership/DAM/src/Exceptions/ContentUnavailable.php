<?php

namespace Mothership\DAM\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The bytes a read asked for are not there (ADR 0009): the asset has no current original, or its blob is
 * missing, the wrong size, or unreadable. ContentStore records each one to storage/logs/content-store.jsonl
 * as it throws. HTTP requests get a 404 (JSON when the request wants JSON); jobs catch it and record the
 * failure instead of skipping silently.
 */
class ContentUnavailable extends RuntimeException
{
    public const NO_ORIGINAL = 'no_original';

    public const BLOB_MISSING = 'blob_missing';

    public const BLOB_UNREADABLE = 'blob_unreadable';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $fileObjectId = null,
        public readonly ?int $assetId = null,
    ) {
        $subject = $fileObjectId !== null ? "file object [{$fileObjectId}]" : "asset [{$assetId}]";

        parent::__construct("Content unavailable ({$reason}) for {$subject}.");
    }

    /** Already recorded by ContentStore when thrown; a missing file is a 404, not an application error. */
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): Response
    {
        return app(ExceptionHandler::class)->render(
            $request,
            new NotFoundHttpException(trans('dam::app.admin.dam.file.not-found'), $this),
        );
    }
}
