<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mothership\DAM\Services\GroqPromptEnhancer;

beforeEach(function () {
    config(['stylepress.enhance.driver' => 'groq', 'stylepress.groq.api_key' => 'gsk-test', 'stylepress.groq.base_url' => 'https://api.groq.com/openai/v1', 'stylepress.groq.text_model' => 'openai/gpt-oss-120b', 'stylepress.groq.vision_model' => 'qwen/qwen3.8-27b']);
});

it('rewrites a text-only brief on the fast text model with low reasoning effort', function () {
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Rewritten request: "A wide shot of the campus quad at golden hour, students walking, banners reading {BRAND_NAME}."']]]])]);
    $text = app(GroqPromptEnhancer::class)->enhance(prompt: 'campus quad, banners {BRAND_NAME}', styleContext: 'STYLE: Cinematic');
    expect($text)->toBe('A wide shot of the campus quad at golden hour, students walking, banners reading {BRAND_NAME}.');
    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_ends_with($request->url(), '/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer gsk-test') && $request->hasHeader('User-Agent', 'Mothership StylePress/1.0')
            && $body['model'] === 'openai/gpt-oss-120b' && $body['reasoning_effort'] === 'low' && $body['temperature'] === 1.0
            && $body['messages'][0]['role'] === 'system' && str_contains($body['messages'][0]['content'], 'Keep slot placeholders')
            && count($body['messages'][1]['content']) === 1 && str_contains($body['messages'][1]['content'][0]['text'], 'USER BRIEF');
    });
});

it('switches to the vision model and attaches bounded reference images', function () {
    $image = UploadedFile::fake()->image('ref.png', 2400, 1600);
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Keep the red cube from the reference, centred on a white sweep.']]]])]);
    $text = app(GroqPromptEnhancer::class)->enhance(prompt: 'the cube', references: [$image->getRealPath()], styleContext: 'STYLE: Product');
    expect($text)->toBe('Keep the red cube from the reference, centred on a white sweep.');
    Http::assertSent(function ($request) {
        $body = $request->data();
        $parts = $body['messages'][1]['content'];

        return $body['model'] === 'qwen/qwen3.8-27b' && $body['reasoning_effort'] === 'none'
            && count($parts) === 2 && $parts[1]['type'] === 'image_url' && str_starts_with($parts[1]['image_url']['url'], 'data:image/jpeg;base64,')
            && strlen($parts[1]['image_url']['url']) < 4000000;
    });
});

it('refuses over-long input, strips thinking tags, and reports outages without leaking details', function () {
    $enhancer = app(GroqPromptEnhancer::class);
    expect(fn () => $enhancer->enhance(prompt: Str::repeat('a', 4001)))->toThrow(RuntimeException::class, 'up to 4000 characters');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => "<think>plan</think>\nA clean request."]]]])
        ->push(['error' => ['message' => 'secret detail']], 503)
        ->push(['error' => ['message' => 'slow down']], 429)]);
    expect($enhancer->enhance(prompt: 'x'))->toBe('A clean request.');
    expect(fn () => $enhancer->enhance(prompt: 'x'))->toThrow(RuntimeException::class, 'unavailable right now');
    expect(fn () => $enhancer->enhance(prompt: 'x'))->toThrow(RuntimeException::class, 'rate limited');
});

it('encodes JPEG and WebP references, skips unreadable files, and caps the image count', function () {
    $jpeg = UploadedFile::fake()->image('a.jpg', 40, 40);
    $webp = UploadedFile::fake()->image('b.webp', 40, 40);
    $png = UploadedFile::fake()->image('c.png', 40, 40);
    $extra = UploadedFile::fake()->image('d.png', 40, 40);
    $text = tempnam(sys_get_temp_dir(), 'not-an-image');
    file_put_contents($text, 'plain text');
    expect(getimagesize($jpeg->getRealPath())[2])->toBe(IMAGETYPE_JPEG)
        ->and(getimagesize($webp->getRealPath())[2])->toBe(IMAGETYPE_WEBP)
        ->and(getimagesize($png->getRealPath())[2])->toBe(IMAGETYPE_PNG);
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Keep the four objects.']]]])]);
    try {
        app(GroqPromptEnhancer::class)->enhance(prompt: 'objects', references: [$text, '/nowhere/missing.png', $jpeg->getRealPath(), $webp->getRealPath(), $png->getRealPath(), $extra->getRealPath()]);
    } finally {
        @unlink($text);
    }
    Http::assertSent(function ($request) {
        $parts = $request->data()['messages'][1]['content'];
        $images = array_values(array_filter($parts, fn ($p) => $p['type'] === 'image_url'));

        return $request->data()['model'] === 'qwen/qwen3.8-27b' && count($images) === GroqPromptEnhancer::MAX_IMAGES
            && collect($images)->every(fn ($p) => str_starts_with($p['image_url']['url'], 'data:image/jpeg;base64,'));
    });
});

it('maps a transport failure to a safe message', function () {
    Http::fake(['api.groq.com/*' => fn () => throw new ConnectionException('timed out')]);
    expect(fn () => app(GroqPromptEnhancer::class)->enhance(prompt: 'x'))->toThrow(RuntimeException::class, 'unavailable right now');
});

it('rejects an empty reply and truncates over-long output', function () {
    $enhancer = app(GroqPromptEnhancer::class);
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => '   ']]]])
        ->push(['choices' => [['message' => ['content' => str_repeat('long ', 1000)]]]])]);
    expect(fn () => $enhancer->enhance(prompt: 'x'))->toThrow(RuntimeException::class, 'did not return');
    expect(mb_strlen($enhancer->enhance(prompt: 'x')))->toBe(GroqPromptEnhancer::MAX_OUTPUT);
});

it('is not configured without the groq driver and a key', function () {
    config(['stylepress.enhance.driver' => 'higgsfield']);
    expect(app(GroqPromptEnhancer::class)->configured())->toBeFalse();
    config(['stylepress.enhance.driver' => 'groq', 'stylepress.groq.api_key' => null]);
    expect(app(GroqPromptEnhancer::class)->configured())->toBeFalse();
});
