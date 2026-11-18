<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;

beforeEach(fn () => config(['ai-index.api' => 'http://bridge.test']));

it('sends base64 images and keeps only vocabulary tags', function () {
    Http::fake(['bridge.test/caption' => Http::response([
        'caption'      => ' A falcon. ', 'tags' => ['photo', 'lion', 'photo'], 'keywords' => ['mascot', ''],
        'visible_text' => 'SALE', 'model' => 'qwen3.5:9b', 'prompt_version' => '2026-10-01.1',
    ])]);

    $result = app(AiBridgeClient::class)->caption(images: ['jpegbytes'], context: ['file_name' => 'a.jpg'], vocabulary: ['photo', 'logo']);

    expect($result['caption'])->toBe('A falcon.')
        ->and($result['tags'])->toBe(['photo'])
        ->and($result['keywords'])->toBe(['mascot']);
    Http::assertSent(fn ($request) => $request['images'] === [base64_encode('jpegbytes')]);
});

it('normalises returned vectors', function () {
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[3, 4]], 'model' => 'm'])]);

    $vectors = app(AiBridgeClient::class)->embed(texts: ['x'], kind: 'query');

    expect($vectors[0][0])->toEqualWithDelta(0.6, 1e-9);
});

it('raises AiBridgeUnavailable for errors, bad bodies and an unset api', function () {
    Http::fake(['bridge.test/embed' => Http::sequence()->push(['error' => 'ollama_failed'], 502)->push(['vectors' => []])]);
    expect(fn () => app(AiBridgeClient::class)->embed(texts: ['x'], kind: 'document'))->toThrow(AiBridgeUnavailable::class);
    expect(fn () => app(AiBridgeClient::class)->embed(texts: ['x'], kind: 'document'))->toThrow(AiBridgeUnavailable::class);

    config(['ai-index.api' => null]);
    expect(app(AiBridgeClient::class)->ready())->toBeFalse()
        ->and(fn () => app(AiBridgeClient::class)->embed(texts: ['x'], kind: 'document'))->toThrow(AiBridgeUnavailable::class);
});
