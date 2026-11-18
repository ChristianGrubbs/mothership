<?php

$viewsPath = dirname(__DIR__, 2).'/src/Resources/views';

$layoutViews = [
    $viewsPath.'/components/layouts/index.blade.php',
    $viewsPath.'/components/layouts/anonymous.blade.php',
    $viewsPath.'/components/layouts/with-history/index.blade.php',
    $viewsPath.'/components/tinymce/index.blade.php',
    $viewsPath.'/emails/layout.blade.php',
];

it('ships admin views without third-party CDN assets', function () use ($layoutViews) {
    foreach ($layoutViews as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->not->toContain('fonts.googleapis.com')
            ->not->toContain('fonts.gstatic.com')
            ->not->toContain('cdnjs.cloudflare.com')
            ->not->toContain('cdn.jsdelivr.net')
            ->not->toContain('unpkg.com');
    }
});

it('loads TinyMCE from the self-hosted build path', function () use ($viewsPath) {
    $contents = file_get_contents($viewsPath.'/components/tinymce/index.blade.php');

    expect($contents)
        ->toContain("asset('themes/admin/default/build/tinymce/tinymce.min.js')")
        ->toContain("base_url: '{{ asset('themes/admin/default/build/tinymce') }}'")
        ->toContain("suffix: '.min'")
        ->toContain("license_key: 'gpl'");
});

it('ships the TinyMCE license and the version selected by the admin lock', function () {
    $root = dirname(__DIR__, 5);
    $buildPath = $root.'/public/themes/admin/default/build/tinymce';
    $blade = file_get_contents(dirname(__DIR__, 2).'/src/Resources/views/components/tinymce/index.blade.php');
    $lock = json_decode(file_get_contents(dirname(__DIR__, 2).'/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $distribution = json_decode(file_get_contents($buildPath.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(preg_match("/plugins: '([^']+)'/", $blade, $pluginMatch))->toBe(1);
    $plugins = preg_split('/\\s+/', $pluginMatch[1]);

    expect(is_readable($buildPath.'/license.md'))->toBeTrue();
    expect(file_get_contents($buildPath.'/license.md'))->toContain('GNU General Public License Version 2 or later');
    expect(is_readable($buildPath.'/notices.txt'))->toBeTrue();
    expect(is_readable($buildPath.'/tinymce.min.js'))->toBeTrue();

    foreach ($plugins as $plugin) {
        expect(is_readable($buildPath.'/plugins/'.$plugin.'/plugin.min.js'))->toBeTrue();
    }

    foreach ([
        'themes/silver/theme.min.js',
        'models/dom/model.min.js',
        'icons/default/icons.min.js',
        'skins/ui/oxide/skin.min.css',
        'skins/ui/oxide-dark/skin.min.css',
        'skins/content/default/content.min.css',
        'skins/content/dark/content.min.css',
    ] as $asset) {
        expect(is_readable($buildPath.'/'.$asset))->toBeTrue();
    }

    expect($distribution['version'])->toBe($lock['packages']['node_modules/tinymce']['version']);
});
