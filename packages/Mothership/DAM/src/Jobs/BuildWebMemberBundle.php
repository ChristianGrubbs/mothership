<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Services\WebPageMembers;

/**
 * Builds one stack member's page into its isolated cache (WebPageMembers). The pin (owning asset, attachment, file and
 * the FULL checksum) is bound at dispatch time and checked again by the service before the build and before it
 * publishes: a revision, move, re-role or removal that lands first makes this job publish nothing.
 */
class BuildWebMemberBundle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The marker WebPageMembers::ensure() leaves is treated as stranded once this much time has passed. */
    public const TIMEOUT = 600;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    /** @param  array{asset: int, attachment: int, file: int, checksum: string, kind: string}  $pin */
    public function __construct(protected array $pin) {}

    public function handle(WebPageMembers $members): void
    {
        $members->build(pin: $this->pin);
    }

    /** A killed or timed-out worker never reaches handle()'s own cleanup: free its marker and staging, cache nothing. */
    public function failed(\Throwable $exception): void
    {
        app(WebPageMembers::class)->abandon(pin: $this->pin);
    }
}
