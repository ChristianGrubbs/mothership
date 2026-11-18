<?php

namespace Mothership\AiAgent\Services;

use Mothership\AiAgent\Contracts\AgentServiceContract;
use Mothership\AiAgent\DTOs\AgentPayload;
use Mothership\AiAgent\DTOs\AgentResult;
use Mothership\AiAgent\Jobs\ExecuteAgentJob;
use Mothership\AiAgent\Pipelines\AgentPipeline;
use Mothership\AiAgent\Pipelines\Stages\BuildPromptStage;
use Mothership\AiAgent\Pipelines\Stages\CallAiProviderStage;
use Mothership\AiAgent\Pipelines\Stages\LogExecutionStage;
use Mothership\AiAgent\Pipelines\Stages\ParseResponseStage;
use Mothership\AiAgent\Pipelines\Stages\ValidateInputStage;
use Mothership\AiAgent\Repositories\AgentExecutionRepository;
use Mothership\AiAgent\Repositories\AgentRepository;

/**
 * Main orchestration service for AI Agent execution.
 *
 * Controllers call this service — never the pipeline or HTTP client directly.
 */
class AgentService implements AgentServiceContract
{
    public function __construct(
        protected AgentPipeline $pipeline,
        protected AgentRepository $agentRepository,
        protected AgentExecutionRepository $executionRepository,
    ) {}

    /**
     * Execute an agent synchronously through the pipeline.
     */
    public function execute(AgentPayload $payload): AgentResult
    {
        $startTime = hrtime(true);

        $execution = $this->executionRepository->logExecution([
            'agentId'      => $payload->agentId,
            'credentialId' => $payload->credentialId,
            'instruction'  => $payload->instruction,
            'status'       => 'running',
        ]);

        $payload = $payload->withMetadata(['executionId' => $execution->id]);

        try {
            $agent = $this->agentRepository->findOrFail($payload->agentId);
            $stages = $this->resolveStages($agent->pipeline ?? []);

            $result = $this->pipeline
                ->through($stages)
                ->process($payload);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $this->executionRepository->markCompleted(
                id: $execution->id,
                output: $result->metadata['aiResponse'] ?? '',
                tokensUsed: $result->metadata['tokensUsed'] ?? 0,
                executionTimeMs: $elapsedMs,
            );

            return AgentResult::success(
                output: $result->metadata['aiResponse'] ?? '',
                data: $result->metadata['parsedData'] ?? [],
                metadata: $result->metadata,
                tokensUsed: $result->metadata['tokensUsed'] ?? 0,
            );
        } catch (\Throwable $e) {
            $this->executionRepository->markFailed($execution->id, $e->getMessage());

            return AgentResult::failure($e->getMessage());
        }
    }

    /**
     * Dispatch agent execution to the queue.
     */
    public function executeAsync(AgentPayload $payload): void
    {
        $execution = $this->executionRepository->logExecution([
            'agentId'      => $payload->agentId,
            'credentialId' => $payload->credentialId,
            'instruction'  => $payload->instruction,
            'status'       => 'queued',
        ]);

        $enriched = $payload->withMetadata(['executionId' => $execution->id]);

        dispatch(new ExecuteAgentJob($enriched->toArray()));
    }

    /**
     * Resolve pipeline stage classes. Falls back to defaults
     * when the agent has no custom pipeline configured.
     *
     * @param  array<string>  $customStages
     * @return array<class-string>
     */
    protected function resolveStages(array $customStages): array
    {
        if ($customStages !== []) {
            return $customStages;
        }

        return $this->defaultStages();
    }

    /**
     * Default pipeline stages in execution order.
     *
     * @return array<class-string>
     */
    public function defaultStages(): array
    {
        return [
            ValidateInputStage::class,
            BuildPromptStage::class,
            CallAiProviderStage::class,
            ParseResponseStage::class,
            LogExecutionStage::class,
        ];
    }
}
