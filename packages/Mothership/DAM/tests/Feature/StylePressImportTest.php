<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\GenerationDirectory;
use Mothership\DAM\Services\StylePressCatalog;
use Mothership\DAM\Services\StylePressCatalogImporter;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->catalogRoot = sys_get_temp_dir().'/stylepress-catalog-'.Str::uuid();
    File::makeDirectory($this->catalogRoot.'/source', 0700, true);
    File::makeDirectory($this->catalogRoot.'/installed', 0700, true);
    config(['stylepress.import_root'=>$this->catalogRoot.'/installed']);
    $image = imagecreatetruecolor(2, 2);
    imagepng($image, $this->catalogRoot.'/source/preview.png');
    imagedestroy($image);
    File::put($this->catalogRoot.'/source/source.md', '# Complete source note');
    $this->catalogPayload = ['schema_version'=> 1, 'version'=>'catalog-one', 'count'=>2, 'categories'=>['04 Style', 'Higgsfield Modes'], 'presets'=>[
        ['slug'=>'real-style', 'title'=>'Real Style', 'category'=>'04 Style', 'categoryLabel'=>'04 Style', 'aspectRatio'=>'1:1', 'nativeFormat'=>'1:1', 'look'=>'Example', 'template'=>'Exact full template', 'slots'=>'Subject', 'image'=>'preview.png', 'generatable'=>true, 'kind'=>'style', 'sourceNote'=>'Styles/04 Style/Real Style.md', 'references'=>['source.md']],
        ['slug'=> 'soul', 'title'=>'Soul', 'category'=>'Higgsfield Modes', 'categoryLabel'=>'Higgsfield Modes', 'aspectRatio'=>'auto', 'nativeFormat'=>'Auto', 'look'=>'Model look', 'template'=>'', 'slots'=>'', 'image'=>null, 'generatable'=>false, 'kind'=>'workflow', 'sourceNote'=>'Styles/Higgsfield Modes/Soul.md', 'references'=>[]],
    ]];
    $this->writeCatalog = function () {
        File::put($this->catalogRoot.'/source/catalog.json', json_encode($this->catalogPayload));

        return $this->catalogRoot.'/source/catalog.json';
    };
});

afterEach(function () {
    File::deleteDirectory($this->catalogRoot);
});

it('replaces the catalog atomically with all entries and preserves complete source attachments', function () {
    $path = ($this->writeCatalog)();
    $importer = app(StylePressCatalogImporter::class);
    $result = $importer->importManifest(manifestPath: $path);
    expect($result['count'])->toBe(2)->and($result['generatable'])->toBe(1);
    $catalog = app(StylePressCatalog::class)->data();
    expect($catalog['presets'][0]['template'])->toBe('Exact full template');
    expect($catalog['presets'][1]['generatable'])->toBeFalse();
    expect(app(StylePressCatalog::class)->thumbnailPath('real-style'))->toBeFile();
    $this->get('/admin/home/stylepress/source/real-style/0')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    $this->get('/admin/home/stylepress/source/real-style/1')->assertNotFound();
    $count = Directory::count();
    $importer->importManifest(manifestPath: $path);
    expect(Directory::count())->toBe($count);
});

it('keeps the old catalog when a replacement is incomplete', function () {
    $importer = app(StylePressCatalogImporter::class);
    $importer->importManifest(manifestPath: ($this->writeCatalog)());
    $this->catalogPayload['version'] = 'catalog-broken';
    $this->catalogPayload['presets'][0]['image'] = 'missing.png';
    $path = ($this->writeCatalog)();
    expect(fn () => $importer->importManifest(manifestPath: $path))->toThrow(RuntimeException::class);
    expect(app(StylePressCatalog::class)->data()['version'])->toBe('catalog-one');
});

it('rejects duplicate identities and does not turn a workflow into a GPT image style', function () {
    $this->catalogPayload['presets'][1]['slug'] = 'real-style';
    expect(fn () => app(StylePressCatalogImporter::class)->importManifest(manifestPath: ($this->writeCatalog)()))->toThrow(RuntimeException::class);
    $this->catalogPayload['presets'][1]['slug'] = 'soul';
    $this->catalogPayload['presets'][1]['generatable'] = true;
    expect(fn () => app(StylePressCatalogImporter::class)->importManifest(manifestPath: ($this->writeCatalog)()))->toThrow(RuntimeException::class);
});

it('routes a generated video with a concept identity into the video concept tree', function () {
    $directory = app(GenerationDirectory::class)->forManifest(manifest: [
        'kind'     => 'video',
        'provider' => 'higgsfield-generate',
        'concept'  => [
            'slug'     => 'vintage-travel-poster',
            'title'    => 'Vintage Travel Poster',
            'category' => '05 Poster and Print Animations',
        ],
    ]);

    expect($directory->generatePath())->toBe(
        'Root/AI-Generations/Video/Concepts/05 Poster and Print Animations/Vintage Travel Poster'
    );
});
