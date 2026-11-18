<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

final class TransferLog
{
    public static function issued(int $adminId): void
    {
        if (! config('dam.transfers.logging')) {
            return;
        }

        static $runId;
        $runId ??= (string) Str::uuid();
        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => $runId,
            'level'  => 'info',
            'event'  => 'grant_issued',
            'loc'    => 'transfers.grant',
            'ctx'    => ['admin_id' => $adminId],
        ];
        file_put_contents(storage_path('logs/dam-transfers.jsonl'), json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}
