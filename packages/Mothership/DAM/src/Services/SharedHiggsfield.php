<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JsonException;
use Mothership\DAM\Support\SharedHiggsfieldError;
use RuntimeException;
use Throwable;

class SharedHiggsfield
{
    /** Default CLI budget for a call that uploads reference images, in seconds. */
    public const REFERENCE_BUDGET_SECONDS = 90;

    /** Default CLI budget for a plain call, in seconds. */
    public const PLAIN_BUDGET_SECONDS = 25;

    /**
     * Anchors the shared deadline once, on this instance's first CLI call, and is never reset by
     * a later call: account status, workspace list, and generate create/get all draw down the
     * SAME window instead of each restarting its own. Until 2026-09-16 this lived in
     * request()->attributes and was force-reset on every single call in console mode
     * (`app()->runningInConsole()` is true for the whole life of a queue worker), which gave
     * every subprocess call in a job its own full budget — so account+create+get inside one
     * GenerateSphereSeamBridge attempt could take up to 6x its nominal budget in real wall time
     * before any one call reported a timeout, well past the job's own $timeout, which then
     * SIGKILLs the worker with no catch block running — started_at set, no provider_request_id,
     * the exact lost-generation state this class exists to prevent.
     *
     * An instance property is the right scope without any console detection at all: this class is
     * never bound as a singleton, so the container hands GenerateSphereSeamBridge::handle() (and
     * each HTTP request's controller) a fresh instance, which is exactly "one operation chain".
     *
     * Nanoseconds from hrtime(true), not microtime(true): microtime reads the system wall clock,
     * which can jump (NTP step, a leap-second slew, a VM pause/resume) mid-instance and either
     * starve every remaining call in the chain or hand back a budget the CLI never actually gets.
     * hrtime is a monotonic clock reading with no such jumps — the correct primitive for a
     * duration, as opposed to a point in time.
     */
    private ?int $deadlineAnchor = null;

    public function enabled(): bool
    {
        return config('stylepress.higgsfield.driver') === 'subscription';
    }

    public function account(): array
    {
        $email = (string) config('stylepress.higgsfield.account_email');
        $workspaceId = (string) config('stylepress.higgsfield.workspace_id');
        $cacheKey = self::class.'.account.'.hash('sha256', $email.'|'.$workspaceId);
        // Request attributes never carry a successful identity check into another
        // HTTP request, including on a long-lived application worker.
        if (! app()->runningInConsole() && request()->attributes->has($cacheKey)) {
            return request()->attributes->get($cacheKey);
        }
        $account = $this->run(arguments: ['account', 'status']);
        if ($email === '' || $workspaceId === '' || strcasecmp($email, (string) ($account['email'] ?? '')) !== 0) {
            // An administrator has to act, but polling an already-submitted generation should keep
            // going meanwhile rather than discard it, so this stays retryable.
            throw new SharedHiggsfieldError(
                'The shared Higgsfield work account needs to be reconnected by an administrator.',
                retryable: true, reason: 'account_unlinked', operation: 'account.status',
            );
        }
        $workspaces = $this->run(arguments: ['workspace', 'list']);
        foreach ($workspaces as $workspace) {
            if (($workspace['id'] ?? null) === $workspaceId && ($workspace['is_selected'] ?? false) === true) {
                $balance = ['credits' => $account['credits'] ?? null, 'account' => 'Shared work subscription'];
                request()->attributes->set($cacheKey, $balance);

                return $balance;
            }
        }
        throw new SharedHiggsfieldError(
            'The shared Higgsfield billing workspace needs to be reselected by an administrator.',
            retryable: true, reason: 'workspace_unselected', operation: 'workspace.list',
        );
    }

    public function estimate(string $prompt, string $aspectRatio, string $quality = 'high', string $resolution = '2k', array $references = [], string $model = 'gpt_image_2', ?string $variant = null): array
    {
        $this->account();
        $ratio = $this->aspectRatio(value: $aspectRatio);
        $estimate = $this->run(arguments: $this->generationArguments(action: 'cost', prompt: $prompt, aspectRatio: $ratio, quality: $quality, resolution: $resolution, references: $references, model: $model, variant: $variant), timeoutSeconds: $references ? 90 : 25);

        return ['credits' => $estimate['credits'] ?? null, 'aspect_ratio' => $ratio];
    }

    /**
     * $timeoutSeconds lets a caller whose queue worker has a shorter leash than this CLI's
     * default budget cap the call, so the worker never SIGKILLs an attempt mid-submission.
     * Omitted, the historical budgets apply unchanged.
     */
    public function submit(string $prompt, string $aspectRatio, string $quality = 'high', string $resolution = '2k', array $references = [], string $model = 'gpt_image_2', ?string $variant = null, ?int $timeoutSeconds = null): string
    {
        $this->account();
        $budget = $timeoutSeconds ?? ($references ? self::REFERENCE_BUDGET_SECONDS : self::PLAIN_BUDGET_SECONDS);
        $job = $this->run(arguments: $this->generationArguments(action: 'create', prompt: $prompt, aspectRatio: $this->aspectRatio(value: $aspectRatio), quality: $quality, resolution: $resolution, references: $references, model: $model, variant: $variant), timeoutSeconds: $budget);
        // Without --wait, CLI 1.1.20 returns a JSON array of ID strings.
        $id = array_is_list($job)
            ? (count($job) === 1 ? $job[0] : null)
            : ($job['id'] ?? $job['job_id'] ?? null);
        if (! is_string($id) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/', $id)) {
            // The create call itself succeeded, so this may well be charged, but there is no id
            // to poll: retrying would only pay again. Terminal, and the caller must say so.
            throw new SharedHiggsfieldError(
                'Higgsfield did not return a usable generation ID.',
                retryable: false, reason: 'no_generation_id', operation: 'generate.create',
            );
        }

        return $id;
    }

    public function poll(string $id): array
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/', $id)) {
            throw new SharedHiggsfieldError('Invalid saved generation ID.', retryable: false, reason: 'invalid_generation_id', operation: 'generate.get');
        }
        $this->account();
        $job = $this->run(arguments: ['generate', 'get', $id]);
        $status = strtolower((string) ($job['status'] ?? ''));
        if (! in_array($status, ['queued', 'in_progress', 'running', 'completed', 'failed', 'nsfw', 'canceled', 'cancelled'], true)) {
            // A state this CLI version does not know is a vocabulary gap, not a lost generation.
            throw new SharedHiggsfieldError('Higgsfield returned an unrecognized job state. Check again shortly.', retryable: true, reason: 'unrecognized_state', operation: 'generate.get');
        }
        $url = $job['result_url'] ?? null;
        if ($status === 'completed' && (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            // Transient by construction: the provider has finished and charged the generation, the
            // CDN URL simply has not landed on this response yet. Treating it as terminal — which
            // GenerateSphereSeamBridge did until 2026-09-16 — throws away a paid result.
            throw new SharedHiggsfieldError('The completed image is not available yet. Check again shortly.', retryable: true, reason: 'result_pending', operation: 'generate.get');
        }

        return ['status' => $status, 'result_url' => $url];
    }

    public function enhance(string $prompt, array $references = [], string $styleContext = ''): string
    {
        $this->account();
        if (mb_strlen($prompt) > 4000 || mb_strlen($styleContext) > 4000) {
            throw new RuntimeException('Enhancement supports requests up to 4000 characters. Shorten the request or generate without enhancement.');
        }
        $arguments = ['product-photoshoot', 'create', '--mode', 'restyle', '--enhance-only', '--prompt', $prompt, '--brand_context', $styleContext];
        foreach ($references as $reference) {
            array_push($arguments, '--image', $reference);
        }
        $response = $this->run(arguments: $arguments, timeoutSeconds: 90);
        $prompts = $response['prompts'] ?? $response['enhanced_prompts'] ?? [];
        $prompt = $response['enhanced_prompt'] ?? $prompts[0] ?? null;
        if (is_array($prompt)) {
            $prompt = $prompt['prompt'] ?? null;
        }
        if (! is_string($prompt) || trim($prompt) === '') {
            throw new RuntimeException('The enhancer did not return an editable prompt.');
        }

        return $prompt;
    }

    private function generationArguments(string $action, string $prompt, string $aspectRatio, string $quality, string $resolution, array $references, string $model = 'gpt_image_2', ?string $variant = null): array
    {
        if (! in_array($quality, ['low', 'medium', 'high'], true) || ! in_array($resolution, ['1k', '2k', '4k'], true)) {
            throw new RuntimeException('Unsupported quality or resolution.');
        }
        // GPT Image 2 (StylePress default) and GPT Image 2.5 (SphereSeam default) share the
        // subscription; 2.5 additionally accepts a "variant" (CLI 1.1.20 model catalog, 2026-09-10).
        if (! in_array($model, ['gpt_image_2', 'gpt_image_2_5'], true) || ($variant !== null && ! in_array($variant, ['flare', 'sunburst'], true))) {
            throw new RuntimeException('Unsupported model or variant.');
        }
        $arguments = ['generate', $action, $model, '--prompt', $prompt, '--aspect_ratio', $aspectRatio, '--quality', $quality, '--resolution', $resolution];
        if ($variant !== null && $model === 'gpt_image_2_5') {
            array_push($arguments, '--variant', $variant);
        }
        foreach ($references as $reference) {
            array_push($arguments, '--image', $reference);
        }

        return $arguments;
    }

    private function aspectRatio(string $value): string
    {
        if (! in_array($value, ['auto', '1:1', '4:3', '3:4', '16:9', '21:9', '9:16', '3:2', '2:3'], true)) {
            throw new RuntimeException('Unsupported output aspect ratio. Choose a supported ratio explicitly.');
        }

        return $value;
    }

    private function run(array $arguments, int $timeoutSeconds = self::PLAIN_BUDGET_SECONDS): array
    {
        $operation = ($arguments[0] ?? 'cli').'.'.($arguments[1] ?? 'run');
        $command = [(string) config('stylepress.higgsfield.cli_binary'), ...$arguments, '--json', '--no-color'];
        $exitCode = null;
        $stderr = '';
        try {
            // Set once per instance (see $deadlineAnchor); every later call on this same instance
            // draws down what is left of ITS OWN nominal budget from that one anchor, so time spent
            // in earlier calls (account status, workspace list) is never handed back for free.
            $this->deadlineAnchor ??= hrtime(true);
            $elapsedSeconds = (hrtime(true) - $this->deadlineAnchor) / 1_000_000_000;
            $remaining = $timeoutSeconds - $elapsedSeconds;
            if ($remaining <= 0) {
                throw new RuntimeException('provider-request-timeout');
            }
            // The vendor manages refreshing and locking its saved session. Only this
            // child process gets a dedicated home; the web account's home is unchanged.
            $result = Process::env(['HOME' => (string) config('stylepress.higgsfield.cli_config_home')])
                ->timeout(max(1, (int) ceil($remaining)))->run($command);
            $exitCode = $result->exitCode();
            $stderr = (string) $result->errorOutput();
            if (! $result->successful()) {
                throw new RuntimeException('provider-process-failed');
            }
            $body = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new RuntimeException('provider-response-invalid');
            }
            HomeTelemetry::record(event: 'subscription.request', ctx: ['operation' => $operation, 'status' => 'ok']);

            return $body;
        } catch (Throwable $exception) {
            HomeTelemetry::record(event: 'subscription.request', ctx: ['operation' => $operation, 'status' => 'unavailable']);
            $excerpt = SharedHiggsfieldError::redact($stderr);
            $reason = match (true) {
                $exception instanceof JsonException                      => 'cli_response_invalid',
                $exception->getMessage() === 'provider-request-timeout'  => 'cli_budget_exhausted',
                $exception->getMessage() === 'provider-response-invalid' => 'cli_response_invalid',
                // Matched by name rather than imported: the Process facade's timeout exception has
                // moved namespace between Laravel minors, and a stale import would silently
                // reclassify every timeout as a generic failure.
                str_contains($exception::class, 'ProcessTimedOut')  => 'cli_timeout',
                $exitCode !== null && $exitCode !== 0               => 'cli_exit_'.$exitCode,
                default                                             => 'cli_unavailable',
            };
            // The only record of why a generation was lost. Redacted: the CLI echoes the command
            // it ran, which carries the full prompt, and its stderr can carry session material.
            Log::error('Shared Higgsfield CLI call failed.', [
                'event' => 'shared_higgsfield.cli.failed',
                'ctx'   => [
                    'operation'      => $operation,
                    'reason'         => $reason,
                    'exit_code'      => $exitCode,
                    'stderr_excerpt' => $excerpt,
                    'error_type'     => $exception::class,
                ],
            ]);
            // Neither CLI output nor exceptions may expose credentials or prompts.
            throw new SharedHiggsfieldError(
                'The shared Higgsfield connection is unavailable. Retry shortly; if it persists, ask an administrator to reconnect the work account.',
                retryable: true, reason: $reason, operation: $operation, exitCode: $exitCode, stderrExcerpt: $excerpt,
            );
        }
    }
}
