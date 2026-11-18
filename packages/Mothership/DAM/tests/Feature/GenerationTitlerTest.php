<?php

use Illuminate\Support\Facades\Http;
use Mothership\DAM\Services\GenerationTitler;
use Mothership\MagicAI\Models\MagicAIPlatform;

$style = ['title' => 'Fun Pop Art Postage Stamps', 'look' => 'Bold halftone stamps'];

function titlerPlatform(string $models, bool $default = true): MagicAIPlatform
{
    return MagicAIPlatform::create(['label' => 'OpenAI '.$models, 'provider' => 'openai', 'api_url' => null, 'api_key' => 'sk-test', 'models' => $models, 'is_default' => $default, 'status' => true]);
}

beforeEach(function () {
    MagicAIPlatform::query()->delete();
    config(['stylepress.title_model' => null]);
});

it('falls back to a one-line style plus first-sentence title when no text platform is configured', function () use ($style) {
    $prompt = "The Color Palette\n\nWhen used with the official logo and fonts, Kai's color palette supports a consistent identity. Deep burgundy\n\n0, 100, 60, 55";
    $result = app(GenerationTitler::class)->title(style: $style, originalPrompt: $prompt);
    expect($result['source'])->toBe('fallback')
        ->and($result['title'])->toBe('Fun Pop Art Postage Stamps — The Color Palette When used with the official logo and fonts, Kai\'s color…')
        ->and($result['title'])->not->toContain("\n")
        ->and(mb_strlen($result['title']))->toBeLessThanOrEqual(GenerationTitler::MAX_LENGTH);
    $titler = app(GenerationTitler::class);
    expect($titler->fallback(style: $style, prompt: ''))->toBe('Fun Pop Art Postage Stamps')
        ->and($titler->fallback(style: $style, prompt: 'Café façade at dusk. Second sentence.'))->toBe('Fun Pop Art Postage Stamps — Café façade at dusk.')
        ->and(mb_strlen($titler->fallback(style: ['title' => str_repeat('Long ', 30)], prompt: str_repeat('word ', 50))))->toBe(GenerationTitler::MAX_LENGTH);
});

it('asks the active OpenAI platform for a short title and cleans the reply', function () use ($style) {
    $platform = titlerPlatform('gpt-image-2, gpt-5-mini');
    $seen = [];
    $titler = new GenerationTitler(function (MagicAIPlatform $used, string $model, string $prompt, string $system) use (&$seen): string {
        $seen = compact('model', 'prompt', 'system') + ['platform' => $used->id];

        return "Title: \"Burgundy Pop Art Stamp Sheet.\"\nSecond line ignored";
    });
    $result = $titler->title(style: $style, originalPrompt: "Color palette stamps\nfor Kai", enhancedPrompt: 'A bright stamp sheet');
    expect($result)->toBe(['title' => 'Burgundy Pop Art Stamp Sheet', 'source' => 'ai'])
        ->and($seen['platform'])->toBe($platform->id)
        ->and($seen['model'])->toBe('gpt-5-mini')
        ->and($seen['prompt'])->toContain('Style: Fun Pop Art Postage Stamps')->toContain('Request: Color palette stamps for Kai')->toContain('Enhanced request: A bright stamp sheet')
        ->and($seen['system'])->toContain('one title only');
});

it('uses the fallback when the platform call fails or the reply is not title-shaped', function () use ($style) {
    titlerPlatform('gpt-5-mini');
    $failing = new GenerationTitler(fn () => throw new RuntimeException('provider down'));
    $empty = new GenerationTitler(fn () => '  "" ');
    $oneWord = new GenerationTitler(fn () => 'Stamps');
    $essay = new GenerationTitler(fn () => str_repeat('word ', 20));
    $fine = new GenerationTitler(fn () => 'Pop Art Stamp Sheet');
    $refusal = new GenerationTitler(fn () => 'Your request was rejected by our safety system');
    $chatty = new GenerationTitler(fn () => "Sure! Here's a title: Stamp Sheet");
    $prose = new GenerationTitler(fn () => 'Stamps are shown. They look bright');
    $comma = new GenerationTitler(fn () => 'Sure, Burgundy Pop Art Stamp Sheet');
    $diagnostic = new GenerationTitler(fn () => 'An error occurred while generating your title.');
    $wont = new GenerationTitler(fn () => "I won't help with that request.");
    $wouldBe = new GenerationTitler(fn () => 'The title would be Burgundy Stamp Sheet');
    $abbrev = new GenerationTitler(fn () => 'Burgundy Stamp Sheet');
    $subtitle = new GenerationTitler(fn () => 'Kai Spirit: Burgundy in Motion.');
    $shortWords = new GenerationTitler(fn () => 'Art is fun. Try bold colors');
    $fitting = new GenerationTitler(fn () => 'A fitting title would be Stamp Sheet');
    $collisions = array_map(fn ($t) => [new GenerationTitler(fn () => $t), $t], ['Your Future Starts Here', 'Student Assistant Recruitment Fair', 'Request for Proposal Workshop', 'U.S. History Week Poster', 'J. Smith Memorial Lecture Banner', 'This Is Home Campus Banner', 'Problem Solving Workshop Poster', 'Issue Resolution Seminar Banner', 'Error Free Coding Bootcamp Flyer']);
    $rejections = array_map(fn ($t) => new GenerationTitler(fn () => $t), ['I refuse to help with that request', 'I must decline this request', 'We must reject that request', 'I’m sorry, but I cannot do that', 'No? Try Another Design', 'Sale! Burgundy Sheet', 'Colors are bright.” Try bold accents', 'Colors are bright.) Try bold accents', 'There was a problem generating a title']);
    expect($failing->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($empty->title(style: $style, originalPrompt: 'A cube'))->toBe(['title' => 'Fun Pop Art Postage Stamps — A cube', 'source' => 'fallback'])
        ->and($oneWord->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($essay->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($refusal->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($chatty->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($prose->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($comma->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($diagnostic->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($wont->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($wouldBe->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($abbrev->title(style: $style, originalPrompt: 'A cube'))->toBe(['title' => 'Burgundy Stamp Sheet', 'source' => 'ai'])
        ->and($subtitle->title(style: $style, originalPrompt: 'A cube'))->toBe(['title' => 'Kai Spirit: Burgundy in Motion', 'source' => 'ai'])
        ->and($shortWords->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback')
        ->and($fitting->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback');
    foreach ($collisions as [$titler, $expected]) {
        expect($titler->title(style: $style, originalPrompt: 'A cube'))->toBe(['title' => $expected, 'source' => 'ai']);
    }
    foreach ($rejections as $titler) {
        expect($titler->title(style: $style, originalPrompt: 'A cube')['source'])->toBe('fallback');
    }
    expect($fine->title(style: $style, originalPrompt: 'A cube'))->toBe(['title' => 'Pop Art Stamp Sheet', 'source' => 'ai']);
});

it('selects a chat-capable text model in a predictable order', function () {
    $titler = app(GenerationTitler::class);
    $platform = titlerPlatform('gpt-image-2, omni-moderation-latest, sora-2, whisper-1, text-embedding-3-small, gpt-5-mini, gpt-5');
    expect($titler->textModel($platform))->toBe('gpt-5-mini');
    config(['stylepress.title_model' => ' gpt-5.2 ']);
    expect($titler->textModel($platform))->toBe('gpt-5.2');
    foreach (['chatgpt-4o-latest', 'o3', 'gpt-5.2-pro'] as $good) {
        expect(GenerationTitler::isChatModel($good))->toBeTrue();
    }
    config(['stylepress.title_model' => null]);
    expect($titler->textModel(titlerPlatform('gpt-4o-mini-tts, gpt-4o-transcribe, gpt-5-computer-use, gpt-5-guard', false)))->toBe('');
    foreach (['omni-moderation-latest', 'gpt-image-2', 'sora-2', 'gpt-5-codex', 'o3-deep-research', 'gpt-4o-search-preview', 'gpt-5-search-api', 'gpt-ranker-1', 'gpt-oss-safeguard-20b', 'o10-mini', 'gpto-5', 'davinci-002', 'gpt-4o-mini-tts', 'gpt-4o-transcribe', 'gpt-5-computer-use', 'gpt-5-guard'] as $bad) {
        config(['stylepress.title_model' => $bad]);
        expect($titler->textModel($platform))->toBe('');
        $asked = false;
        (new GenerationTitler(function () use (&$asked) {
            $asked = true;

            return 'Never Asked Title Here';
        }))->title(style: ['title' => 'S'], originalPrompt: 'A cube');
        expect($asked)->toBeFalse();
    }
    config(['stylepress.title_model' => null]);
    MagicAIPlatform::query()->delete();
    titlerPlatform('gpt-5', false)->update(['status' => false]);
    MagicAIPlatform::create(['label' => 'Anthropic', 'provider' => 'anthropic', 'api_url' => null, 'api_key' => 'ak-test', 'models' => 'claude-sonnet-5', 'is_default' => true, 'status' => true]);
    expect((new GenerationTitler(fn () => 'Should Not Be Asked At All'))->title(style: ['title' => 'S'], originalPrompt: 'A cube')['source'])->toBe('fallback');
    $second = titlerPlatform('gpt-5-mini', false);
    $first = titlerPlatform('gpt-5-nano', true);
    $seen = null;
    (new GenerationTitler(function (MagicAIPlatform $used, string $model) use (&$seen) {
        $seen = [$used->id, $model];

        return 'Precedence Title Check';
    }))->title(style: ['title' => 'S'], originalPrompt: 'A cube');
    expect($seen)->toBe([$first->id, 'gpt-5-nano']);
    expect($titler->textModel(titlerPlatform('gpt-image-2, dall-e-3', false)))->toBe('')
        ->and($titler->textModel(null))->toBe('')
        ->and(GenerationTitler::isChatModel('o4-mini'))->toBeTrue()
        ->and(GenerationTitler::isChatModel('gpt-4o-audio-preview'))->toBeFalse()
        ->and(GenerationTitler::isChatModel('gpt-4o-realtime-preview'))->toBeFalse();
});

it('talks to the platform directly with a short timeout and reads the completion', function () use ($style) {
    titlerPlatform('gpt-5-mini');
    config(['stylepress.title_timeout' => 7]);
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'Burgundy Halftone'], ['type' => 'text', 'text' => 'Stamp Sheet']]]]]])]);
    $result = app(GenerationTitler::class)->title(style: $style, originalPrompt: 'Stamps for Kai');
    expect($result)->toBe(['title' => 'Burgundy Halftone Stamp Sheet', 'source' => 'ai']);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'gpt-5-mini' && $request['max_completion_tokens'] === 60
        && $request['messages'][1]['content'] === "Style: Fun Pop Art Postage Stamps\nStyle look: Bold halftone stamps\nRequest: Stamps for Kai");
});

it('falls back when the platform answers with an error status', function () use ($style) {
    titlerPlatform('gpt-5-mini');
    Http::fake(['api.openai.com/*' => Http::response('slow down', 429)]);
    expect(app(GenerationTitler::class)->title(style: $style, originalPrompt: 'Stamps for Kai')['source'])->toBe('fallback');
});
