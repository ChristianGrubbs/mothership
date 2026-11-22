<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/** HTTP client for tools/studio/ai-service/server.py (pattern: Optimization/Optimizer.php). */
class AiBridgeClient
{
    public function ready(): bool
    {
        return filled(config('ai-index.api'));
    }

    /**
     * Whether the service answers: true, false, or null when that is not known yet. Never makes a page wait on a down
     * bridge: the answer is cached for `health_ttl` seconds, and when it has expired a single request (the one that wins
     * a short lock) probes `/health` (2 s at most). Every other caller meanwhile gets the last known answer, or null when
     * the service has never been probed: a caller must not read null as up or as down. A bridge whose `/health` says
     * Ollama is down or a model is missing is down. Not configured is false. Off
     * (configured means reachable) with MOTHERSHIP_AI_HEALTH_CHECK=false.
     */
    public function health(): ?bool
    {
        if (! $this->ready()) {
            return false;
        }

        if (! config('ai-index.health_check')) {
            return true;
        }

        $key = 'dam.ai-bridge.reachable.'.md5((string) config('ai-index.api'));
        $fresh = Cache::get($key);

        if (is_bool($fresh)) {
            return $fresh;
        }

        $lock = Cache::lock($key.'.probe', 5);

        if (! $lock->get()) {
            $last = Cache::get($key.'.last');

            return is_bool($last) ? $last : null;
        }

        try {
            // The probe that held the lock before us has just stored its answer: use it.
            $fresh = Cache::get($key);

            if (is_bool($fresh)) {
                return $fresh;
            }

            try {
                $response = Http::connectTimeout(2)->timeout(2)->acceptJson()->get(rtrim((string) config('ai-index.api'), '/').'/health');
                // Up means the bridge can do the work, not only that it answers: Ollama must answer and list both the
                // caption and the embedding model. A bridge that answers 200 while Ollama lists no models would otherwise
                // read as up, and every caller (the hourly --transient reconcile first) would spend attempts on jobs that
                // fail in a tenth of a second. A field the bridge does not send is not read as down.
                $models = $response->json('models');
                $up = $response->successful()
                    && $response->json('ok') !== false
                    && $response->json('ollama') !== false
                    && ! (is_array($models) && in_array(false, $models, true));
            } catch (Throwable) {
                $up = false;
            }

            Cache::put($key, $up, max(1, (int) config('ai-index.health_ttl')));
            Cache::put($key.'.last', $up, 86400);

            return $up;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $images  raw JPEG bytes, 1..4
     * @param  array{file_name?: string, folder_path?: string, kind?: string}  $context
     * @param  list<string>  $vocabulary
     * @return array{caption: string, tags: list<string>, keywords: list<string>, visible_text: string, model: string, prompt_version: string}
     */
    public function caption(array $images, array $context, array $vocabulary): array
    {
        $body = $this->post(path: '/caption', payload: [
            'images'     => array_map('base64_encode', array_values($images)),
            'context'    => $context,
            'vocabulary' => array_values($vocabulary),
        ], timeout: (float) config('ai-index.caption_timeout'));

        if (! is_string($body['caption'] ?? null) || ! is_array($body['tags'] ?? null)) {
            throw new AiBridgeUnavailable('AI bridge returned an unusable caption.');
        }

        return [
            'caption'        => trim($body['caption']),
            'tags'           => array_values(array_unique(array_intersect(array_filter($body['tags'], 'is_string'), $vocabulary))),
            'keywords'       => array_values(array_filter((array) ($body['keywords'] ?? []), fn ($k) => is_string($k) && trim($k) !== '')),
            'visible_text'   => is_string($body['visible_text'] ?? null) ? trim($body['visible_text']) : '',
            'model'          => (string) ($body['model'] ?? ''),
            'prompt_version' => (string) ($body['prompt_version'] ?? ''),
        ];
    }

    /**
     * Rename with AI: a file name subject, place and title from an existing description (text only, no images).
     *
     * @param  array<string, mixed>  $context  caption, keywords, tags, visible_text, file_name, folder_path, captured_at, campus, building, events
     * @return array{subject: string, place: string, title: string, event: string}
     */
    public function name(array $context): array
    {
        $body = $this->post(path: '/name', payload: $context, timeout: (float) config('ai-index.name_timeout'));

        if (! is_string($body['subject'] ?? null)) {
            throw new AiBridgeUnavailable('AI bridge returned an unusable name.');
        }

        return [
            'subject' => trim($body['subject']),
            'place'   => is_string($body['place'] ?? null) ? trim($body['place']) : '',
            'title'   => is_string($body['title'] ?? null) ? trim($body['title']) : '',
            'event'   => is_string($body['event'] ?? null) ? trim($body['event']) : '',
        ];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, string $kind, ?float $timeout = null): array
    {
        $body = $this->post(path: '/embed', payload: ['texts' => array_values($texts), 'kind' => $kind], timeout: $timeout ?? (float) config('ai-index.embed_timeout'));
        $vectors = $body['vectors'] ?? null;

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new AiBridgeUnavailable('AI bridge returned unusable vectors.');
        }

        try {
            return array_map(fn ($vector) => VectorCodec::normalize(is_array($vector) ? $vector : []), array_values($vectors));
        } catch (InvalidArgumentException) {
            throw new AiBridgeUnavailable('AI bridge returned unusable vectors.');
        }
    }

    public function embedModel(): string
    {
        return 'qwen3-embedding:0.6b';
    }

    private function post(string $path, array $payload, float $timeout): array
    {
        if (! $this->ready()) {
            throw new AiBridgeUnavailable('AI bridge is not configured.');
        }

        try {
            $response = $this->client(timeout: $timeout)->post(rtrim((string) config('ai-index.api'), '/').$path, $payload);
        } catch (Throwable) {
            throw new AiBridgeUnavailable('AI bridge unreachable.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new AiBridgeUnavailable('AI bridge answered '.$response->status().'.');
        }

        return $response->json();
    }

    private function client(float $timeout): PendingRequest
    {
        return Http::connectTimeout((int) config('ai-index.connect_timeout'))
            ->timeout(max(1, (int) ceil($timeout)))
            ->acceptJson()
            ->asJson();
    }
}
