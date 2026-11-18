<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;

final class FolderAccessLog
{
    public static function write(string $event, array $ctx): void
    {
        // Only typed identifiers/counts reach either sink; never request bodies or names.
        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value));

        // A change to who can reach what is a security event whether or not the opt-in JSONL is on (P1a).
        if (isset(SecurityEvent::ACCESS_CHANGES[$event])) {
            $actorId = $ctx['actor_id'] ?? null;

            app(SecurityEventRecorder::class)->record(new SecurityEvent(
                name: SecurityEvent::ACCESS_CHANGES[$event],
                guard: auth('admin')->check() ? 'admin' : null,
                actorAdminId: is_int($actorId) && $actorId > 0 ? $actorId : null,
                ctx: $ctx,
            ));
        }

        if (! config('dam.access_logging', false)) {
            return;
        }

        $record = ['ts' => now()->toIso8601String(), 'run_id' => (string) Str::uuid(), 'level' => 'info',
            'event'     => $event, 'loc' => 'folder-access', 'ctx' => RequestId::ctx($ctx)];
        file_put_contents(storage_path('logs/folder-access.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
