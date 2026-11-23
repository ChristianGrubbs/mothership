<?php

namespace Mothership\DAM\Mcp;

use Illuminate\Support\Str;

/**
 * Redacted JSONL at storage/logs/mothership-mcp.jsonl, written only when MOTHERSHIP_MCP_LOGGING is on. Events carry
 * fixed codes, counts, sizes and outcomes only: never a path, file name, title, tag, note or byte content. run_id is
 * one id per server process, so one agent's session reads as one run.
 */
final class MothershipLog
{
    /** Context keys an event may carry; anything else is dropped before the sink. */
    private const KEYS = ['operation', 'ok', 'outcome', 'reason', 'size', 'mode', 'replayed', 'count', 'reused', 'via'];

    private static ?string $runId = null;

    /** @param  string  $via  stdio (the artisan server) or rest (LibraryToolController) */
    public static function operation(string $operation, bool $ok, ?string $reason = null, string $via = 'stdio'): void
    {
        self::write(level: $ok ? 'info' : 'warning', event: 'mothership.operation', loc: 'LibraryTool::perform', ctx: ['operation' => $operation, 'ok' => $ok, 'reason' => $reason, 'via' => $via]);
    }

    /** @param array<string, scalar|null> $ctx */
    public static function decision(string $event, string $loc, array $ctx, string $level = 'info'): void
    {
        self::write(level: $level, event: 'mothership.'.$event, loc: $loc, ctx: $ctx);
    }

    /** @param array<string, mixed> $ctx */
    private static function write(string $level, string $event, string $loc, array $ctx): void
    {
        if (! config('mothership-mcp.logging', false)) {
            return;
        }
        try {
            $ctx = array_filter(
                array_intersect_key($ctx, array_flip(self::KEYS)),
                fn (mixed $value): bool => $value !== null && is_scalar($value),
            );
            $line = json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => self::$runId ??= (string) Str::uuid(),
                'level'  => $level,
                'event'  => $event,
                'loc'    => $loc,
                'ctx'    => (object) $ctx,
            ], JSON_THROW_ON_ERROR);
            file_put_contents(storage_path('logs/mothership-mcp.jsonl'), $line."\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Optional diagnostics never change an operation's outcome.
        }
    }
}
