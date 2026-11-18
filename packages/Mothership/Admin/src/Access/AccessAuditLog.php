<?php

namespace Mothership\Admin\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * JSONL audit trail for sign-in access requests and every access decision, at
 * storage/logs/access-grants.jsonl. One line per event: ts, run_id, level, event, loc, ctx.
 * Emails are written as keyed hashes, never in clear. MOTHERSHIP_AUTH_LOG=false turns it off.
 * A line written inside a transaction waits for its commit, so a rolled-back decision leaves none.
 */
class AccessAuditLog
{
    private ?string $runId = null;

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function write(string $event, array $ctx = [], string $level = 'info'): void
    {
        if (! config('admin.auth.access_log', true)) {
            return;
        }

        $line = json_encode([
            'ts'     => now()->toIso8601ZuluString('microsecond'),
            'run_id' => $this->runId ??= (string) Str::uuid(),
            'level'  => $level,
            'event'  => $event,
            'loc'    => app()->runningInConsole() ? 'console' : 'http',
            'ctx'    => $ctx,
        ], JSON_UNESCAPED_SLASHES);

        DB::afterCommit(function () use ($line): void {
            try {
                file_put_contents(storage_path('logs/access-grants.jsonl'), $line."\n", FILE_APPEND | LOCK_EX);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function hashEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        return substr(hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key')), 0, 16);
    }
}
