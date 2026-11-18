<?php

declare(strict_types=1);

namespace Mothership\DAM\Listeners;

use Mothership\AdminApi\Services\McpTokenExchanged;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Support\AnalyticsLog;
use Throwable;

/**
 * MCP connector token exchanges as first-party analytics: refresh grants mean a connection lasted, code grants
 * mean someone consented again. An issued token belongs to its account (from Passport, never the browser guard);
 * a refused one names no account and is recorded on the system actor. This runs after Passport has issued and
 * possibly rotated credentials, so nothing here may fail the exchange: every lookup is inside the catch.
 */
class RecordMcpTokenExchange
{
    public function __construct(private readonly ActorResolver $actors) {}

    public function handle(McpTokenExchanged $exchange): void
    {
        if (! config('dam_events.enabled')) {
            return;
        }

        try {
            $props = ['grant_type' => $exchange->grantType, 'provider' => $exchange->provider];

            if ($exchange->issued()) {
                DamEvents::emit(event: DamEvent::McpTokenIssued, props: $props, surface: 'api',
                    actor: $this->actors->forAdminId(adminId: $exchange->userId));

                return;
            }

            DamEvents::emit(event: DamEvent::McpTokenFailed, surface: 'api', actor: AnalyticsActor::system(),
                props: [...$props, 'status' => $exchange->status, 'error_code' => $exchange->error ?? 'unknown']);
        } catch (Throwable $error) {
            AnalyticsLog::write(level: 'error', event: 'analytics.mcp_token_failed', ctx: ['exception' => $error::class]);
        }
    }
}
