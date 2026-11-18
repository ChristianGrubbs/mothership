<?php

namespace Mothership\AiAgent\Contracts;

use Mothership\AiAgent\DTOs\AgentPayload;
use Mothership\AiAgent\DTOs\AgentResult;

/**
 * Contract for the main Agent orchestration service.
 */
interface AgentServiceContract
{
    /**
     * Execute an agent with the given payload.
     */
    public function execute(AgentPayload $payload): AgentResult;

    /**
     * Execute an agent asynchronously via a queued job.
     */
    public function executeAsync(AgentPayload $payload): void;
}
