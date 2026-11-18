<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The one correlation id a request, its log lines, its queued jobs and the status rows they write share
 * (P1a, §3.6.11). AssignRequestId puts it on Laravel's Context, which Laravel itself writes into every queued
 * job's payload and restores before the job runs, so a worker sees the id of the request that dispatched it.
 * A scheduled command or a job nobody's request dispatched has none, and nothing here invents one.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTEXT_KEY = 'request_id';

    /** A caller-supplied id is kept only when it is this shape; anything else is replaced, never echoed. */
    private const SAFE = '/\A[A-Za-z0-9._-]{8,64}\z/';

    public static function accept(?string $inbound): string
    {
        return is_string($inbound) && preg_match(self::SAFE, $inbound) === 1 ? $inbound : (string) Str::uuid();
    }

    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * A JSONL sink's ctx with the request id added, after the sink's own allow-list ran, so no sink has to
     * list the key. Unchanged when there is no id.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function ctx(array $ctx): array
    {
        $id = self::current();

        return $id === null ? $ctx : $ctx + [self::CONTEXT_KEY => $id];
    }
}
