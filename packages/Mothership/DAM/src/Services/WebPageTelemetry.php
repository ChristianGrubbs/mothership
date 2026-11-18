<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

class WebPageTelemetry
{
    public const KEYS = ['asset_id', 'version', 'file_count', 'total_bytes', 'entry', 'reason', 'path',
        'collection_id', 'parent_id', 'admin_id', 'children', 'pages', 'placed', 'rejected',
        'attachment_id', 'file_id', 'kind', 'state'];

    public static function record(string $event, array $ctx): void
    {
        if (! config('dam.library_log_enabled')) {
            return;
        }

        $request = app()->bound('request') ? request() : null;
        $runId = $request?->attributes->get('web_pages_run_id') ?? (string) Str::uuid();
        $request?->attributes->set('web_pages_run_id', $runId);

        $record = [
            'ts'    => now()->toIso8601String(), 'run_id' => $runId,
            'level' => str_ends_with($event, '_failed') || str_ends_with($event, '_rejected') || str_contains($event, 'forbidden') ? 'warning' : 'info',
            'event' => $event, 'loc' => 'dam.web_pages',
            'ctx'   => RequestId::ctx(array_intersect_key($ctx, array_flip(self::KEYS))),
        ];
        @file_put_contents(storage_path('logs/web-pages.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
