<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

class HomeTelemetry
{
    /** Only structural metadata belongs here; never prompts, state, URLs or credentials. */
    public static function record(string $event, array $ctx = [], string $level = 'info'): void
    {
        if (! config('dam-home.log_enabled')) {
            return;
        }

        $request = request();
        $runId = $request->attributes->get('home_run_id') ?? (string) Str::uuid();
        $request->attributes->set('home_run_id', $runId);
        $record = [
            'ts'    => now()->toIso8601String(), 'run_id' => $runId, 'level' => $level,
            'event' => $event, 'loc' => 'dam.home',
            'ctx'   => RequestId::ctx(array_intersect_key($ctx, array_flip(['admin_id', 'widget', 'count', 'revision', 'service', 'status', 'generation_id', 'asset_id', 'phase', 'exception', 'action', 'references', 'source', 'reason', 'model', 'length', 'driver', 'vision', 'ms', 'chars', 'prompt_version']))),
        ];
        // Diagnostics must not make a successful user operation fail on a full disk.
        @file_put_contents(storage_path('logs/home.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
