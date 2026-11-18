<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

/** Identifier-only debug events; prompts, provider responses and credentials never reach this sink. */
class ImageEditorLog
{
    public static function write(string $event, array $ctx = []): void
    {
        if (! config('app.debug')) {
            return;
        }
        $safe = array_intersect_key($ctx, array_flip([
            'asset_id', 'version_id', 'draft_id', 'resource_id', 'provider', 'model',
            'status', 'action', 'scope', 'duration_ms', 'prompt_version', 'reason', 'error_class',
            'original_width', 'original_height', 'working_width', 'working_height', 'reduced', 'error_type',
        ]));
        try {
            File::append(storage_path('logs/image-editor.jsonl'), json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => $safe['draft_id'] ?? (string) Str::uuid(),
                'level'  => 'debug',
                'event'  => $event,
                'loc'    => 'image-editor',
                'ctx'    => RequestId::ctx($safe),
            ], JSON_THROW_ON_ERROR).PHP_EOL, true);
        } catch (\Throwable) {
            // Diagnostic output must never change the outcome of an image operation.
        }
    }
}
