<?php

// The light toolbar surface is the light-theme --c-primary-100 token. Compiled rules name the
// token rather than its value, which keeps the dark-mode "not a light surface" checks meaningful across re-skins.
function editorLightSurface(): string
{
    $source = file_get_contents(base_path('packages/Mothership/Admin/src/Resources/assets/css/app.css'));

    expect($source)->toMatch('/:root\s*\{[^}]*--c-primary-100:\s*[\d ]+;/');

    return 'var(--c-primary-100)';
}

const EDITOR_TOOLBAR_BUTTON = '.tox .tox-toolbar__group:last-child button';

const EDITOR_TOOLBAR_HOVER = '.tox .tox-tbtn:hover';

function compiledAdminStyles(): string
{
    $build = base_path('public/themes/admin/default/build');

    $manifest = json_decode(file_get_contents($build.'/manifest.json'), true);

    return file_get_contents($build.'/'.$manifest['src/Resources/assets/css/app.css']['file']);
}

function compiledRule(string $selector): string
{
    $styles = compiledAdminStyles();

    $offset = strpos($styles, $selector.'{');

    expect($offset)->not->toBeFalse("no compiled rule for {$selector}");

    $offset += strlen($selector) + 1;

    return substr($styles, $offset, strpos($styles, '}', $offset) - $offset);
}

function darkVariantOf(string $selector): string
{
    return $selector.':is(.dark *)';
}

it('does not leave the toolbar buttons on a light surface in dark mode', function () {
    expect(compiledRule(darkVariantOf(EDITOR_TOOLBAR_BUTTON)))
        ->not->toContain(editorLightSurface());
});

it('does not leave a hovered toolbar button on a light surface in dark mode', function () {
    expect(compiledRule(darkVariantOf(EDITOR_TOOLBAR_HOVER)))
        ->not->toContain(editorLightSurface());
});

it('draws the toolbar icons white in dark mode', function () {
    expect(compiledRule('.dark .tox .tox-tbtn svg'))->toContain('fill:#fff');
});

it('lets the dark rules override the light ones', function (string $selector) {
    $styles = compiledAdminStyles();

    expect(strpos($styles, darkVariantOf($selector).'{'))
        ->toBeGreaterThan(strpos($styles, $selector.'{'));
})->with([EDITOR_TOOLBAR_BUTTON, EDITOR_TOOLBAR_HOVER]);

it('keeps the light surface for the same buttons outside dark mode', function (string $selector) {
    expect(compiledRule($selector))->toContain(editorLightSurface());
})->with([EDITOR_TOOLBAR_BUTTON, EDITOR_TOOLBAR_HOVER]);

it('serves the compiled stylesheet the manifest points at', function () {
    $build = base_path('public/themes/admin/default/build');

    $manifest = json_decode(file_get_contents($build.'/manifest.json'), true);

    expect($build.'/'.$manifest['src/Resources/assets/css/app.css']['file'])->toBeReadableFile();
});

it('carries every dark toolbar rule from the source into the build', function () {
    $source = file_get_contents(base_path('packages/Mothership/Admin/src/Resources/assets/css/app.css'));

    preg_match_all('/\.tox[^{]*\{[^}]*dark:[^}]*}/', $source, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $rule) {
        preg_match('/^(\.tox[^{]*)\{/', $rule, $selector);

        expect(compiledAdminStyles())->toContain(darkVariantOf(trim($selector[1])).'{');
    }
});
