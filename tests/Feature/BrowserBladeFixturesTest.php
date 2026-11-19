<?php

// The Node browser tests under tools/browser/tests mount real Blade components under Vue in Chromium. Node cannot run
// Blade, and pulling templates out of .blade.php source with regexes broke 33 of those tests on 2026-10-20 (PR #306) when
// @if, @lang and <x-dam::> reached the extracted text. So Laravel renders the views here, and the parts those tests
// load are committed under tools/browser/fixtures/blade/. This test fails when a view changed and the fixtures did not.
//
// Regenerate after changing one of these views:
//   BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\User\Models\Admin;

const BLADE_FIXTURES_DIR = __DIR__.'/../../tools/browser/fixtures/blade';

// The values the browser tests set themselves: each `@json(<expression>)` named here renders as a read of
// window.__bladeFixtureJson.<key>, which the test fills in before it runs the component script.
const BLADE_FIXTURE_JSON = ['$config' => 'config'];

// Compiles into a private directory, so views compiled with the @json stand-in never reach the shared view cache.
function bladeFixturesCompiler(): string
{
    $compiled = sys_get_temp_dir().'/blade-fixtures-'.Str::random(12);
    File::ensureDirectoryExists($compiled);

    $blade = app('blade.compiler');
    (fn () => $this->cachePath = $compiled)->call($blade);

    $blade->directive('json', function (string $expression) use ($blade) {
        $key = BLADE_FIXTURE_JSON[trim($expression)] ?? null;

        return $key !== null
            ? 'window.__bladeFixtureJson.'.$key
            : (fn () => $this->compileJson("({$expression})"))->call($blade);
    });

    return $compiled;
}

function bladeFixturesDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

// A component as its @pushOnce('scripts') block writes it: the x-template, then the module script that registers it.
function bladeFixturesComponent(HTMLDocument $document, string $templateId): array
{
    $template = $document->getElementById($templateId);
    expect($template)->not->toBeNull("#{$templateId} is rendered on the page");

    $script = $template->nextElementSibling;
    $component = str_replace('-template', '', $templateId);
    // Some views put a shared mixin between their template and registration.
    while ($script?->tagName === 'SCRIPT' && ! str_contains($script->textContent, "app.component('{$component}'")) {
        $script = $script->nextElementSibling;
    }
    expect($script?->tagName)->toBe('SCRIPT', "#{$templateId} is followed by its registration script")
        ->and($script->getAttribute('type'))->toBe('module', "#{$templateId} is followed by its registration script");

    return ['template' => $template->textContent, 'script' => $script->textContent];
}

// Every fixture file, freshly rendered, keyed by its name under tools/browser/fixtures/blade/.
function bladeFixturesRender(): array
{
    Storage::fake(Directory::getAssetDisk());
    // A fixed name, no avatar image, URL root and CSRF token: the account menu renders the same bytes on every machine.
    URL::forceRootUrl('http://mothership.test');
    test()->withSession(['_token' => 'blade-fixture-csrf-token']);
    test()->loginAsAdmin(Admin::factory()->create(['name' => 'Fixture Admin', 'image' => null, 'use_gravatar' => false]));

    $home = bladeFixturesDocument(test()->get(route('admin.home.index'))->assertOk()->getContent());
    config(['dam.explorer.enabled' => true]);
    $library = bladeFixturesDocument(test()->get(route('admin.dam.index'))->assertOk()->getContent());

    $files = [];
    foreach ([
        'asset-picker'  => [$library, 'v-dam-asset-picker-template'],
        'dropdown'      => [$home, 'v-dropdown-template'],
        'explorer-tab'  => [$library, 'v-dam-tab-template'],
        'explorer-grid' => [$library, 'v-dam-explorer-grid-template'],
        'explorer-list' => [$library, 'v-dam-explorer-list-template'],
        'folder-picker' => [$library, 'v-dam-folder-picker-template'],
    ] as $name => [$document, $templateId]) {
        $parts = bladeFixturesComponent($document, $templateId);
        $files["{$name}.template.html"] = $parts['template'];
        $files["{$name}.js"] = $parts['script'];
    }

    // The drag-select mixin the explorer's grid and list share.
    $marquee = collect($library->querySelectorAll('script'))
        ->filter(fn (Element $script) => str_contains($script->textContent, 'window.MothershipExplorerMarquee = {'))
        ->values();
    expect($marquee)->toHaveCount(1, 'the Library renders the drag-select mixin once');
    $files['explorer-marquee.js'] = $marquee[0]->textContent;

    // The header's avatar dropdown, as the browser receives it (the account menu is in its content slot).
    $accountMenu = collect($home->querySelectorAll('header v-dropdown'))
        ->map(fn (Element $element) => $home->saveHtml($element))
        ->filter(fn (string $html) => str_contains($html, 'data-account-menu'))
        ->values();
    expect($accountMenu)->toHaveCount(1, 'the header renders one avatar dropdown with the account menu');
    $files['account-menu.html'] = $accountMenu[0];

    // The tool frame page's root, as the browser receives it: tool-frame.js starts from this markup.
    $frame = bladeFixturesDocument(test()->get(route('admin.tools.show', ['widget' => 'sound-effects']))->assertOk()->getContent());
    $toolFrame = $frame->querySelector('[data-tool-frame]');
    expect($toolFrame)->not->toBeNull('the tool frame page renders its root');
    // The account's id differs from one database to the next; the tool only passes it on.
    $toolFrame->setAttribute('data-user', '1');
    $files['tool-frame.html'] = $frame->saveHtml($toolFrame);

    return array_map(fn (string $contents) => rtrim($contents)."\n", $files);
}

it('keeps the browser-test Blade fixtures in step with the views they are rendered from', function () {
    $compiled = bladeFixturesCompiler();

    try {
        // The committed explorer fixture includes the date-range shortcuts; midnight must not change its bytes.
        $files = Carbon::withTestNow('2026-10-23 12:00:00 UTC', fn () => bladeFixturesRender());
    } finally {
        File::deleteDirectory($compiled);
    }

    if (getenv('BLADE_FIXTURES_WRITE')) {
        File::ensureDirectoryExists(BLADE_FIXTURES_DIR);
        foreach ($files as $name => $contents) {
            File::put(BLADE_FIXTURES_DIR."/{$name}", $contents);
        }
    }

    foreach ($files as $name => $contents) {
        $path = BLADE_FIXTURES_DIR."/{$name}";
        expect(is_file($path) ? file_get_contents($path) : null)->toBe($contents,
            "tools/browser/fixtures/blade/{$name} is stale; run BLADE_FIXTURES_WRITE=1 vendor/bin/pest tests/Feature/BrowserBladeFixturesTest.php");
    }
});
