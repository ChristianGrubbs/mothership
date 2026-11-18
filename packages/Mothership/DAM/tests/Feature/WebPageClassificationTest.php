<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\WebPageInspector;

uses(DatabaseTransactions::class);

it('stores web as a file_type', function () {
    $asset = Asset::factory()->create(['file_type' => 'web', 'file_name' => 'site.zip', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => 'assets/Root/site.zip']);

    expect($asset->fresh()->file_type)->toBe('web');
});

it('exposes the web_pages config defaults', function () {
    expect(config('dam.web_pages.enabled'))->toBeTrue()
        ->and(config('dam.web_pages.max_bundle_size'))->toBe(536870912)
        ->and(config('dam.web_pages.max_entries'))->toBe(5000);
});

function makeZip(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $path;
}

it('classifies html and htm as web', function () {
    expect(AssetHelper::classifyFile('text/html', 'html'))->toBe('web')
        ->and(AssetHelper::classifyFile('text/plain', 'htm'))->toBe('web');
});

it('classifies a zip with a root index.html as web', function () {
    expect(AssetHelper::classifyFile('application/zip', 'zip', makeZip(['index.html' => '<h1>x</h1>', 'css/a.css' => 'h1{}'])))->toBe('web');
});

it('classifies a zip whose single top-level folder holds index.html as web', function () {
    $report = app(WebPageInspector::class)->inspect(makeZip(['site/index.html' => '<h1>x</h1>', 'site/app.js' => '1']));
    expect($report)->toMatchArray(['is_web' => true, 'prefix' => 'site/', 'forbidden_entry' => null]);
});

it('takes a lone html file beside a site folder as the entry (exporter "OPEN ME" shape)', function () {
    $report = app(WebPageInspector::class)->inspect(makeZip([
        'Export/OPEN ME - 07.html'            => '<meta http-equiv="refresh" content="0; url=site/index.html">',
        'Export/README.txt'                   => 'x',
        'Export/site/index.html'              => '<h1>x</h1>',
        '__MACOSX/Export/._OPEN ME - 07.html' => 'x',
    ]));

    expect($report)->toMatchArray(['is_web' => true, 'prefix' => 'Export/', 'entry' => 'OPEN ME - 07.html', 'reason' => null]);
});

it('takes a lone root .htm file as the entry', function () {
    expect(app(WebPageInspector::class)->inspect(makeZip(['page.htm' => 'x', 'img/a.png' => '1'])))
        ->toMatchArray(['is_web' => true, 'prefix' => '', 'entry' => 'page.htm']);
});

it('takes a lone non-index html inside the single top-level folder as the entry', function () {
    expect(app(WebPageInspector::class)->inspect(makeZip(['Top/start.html' => 'x', 'Top/js/app.js' => '1'])))
        ->toMatchArray(['is_web' => true, 'prefix' => 'Top/', 'entry' => 'start.html']);
});

it('prefers index.html over another html file at the same level', function () {
    expect(app(WebPageInspector::class)->inspect(makeZip(['Top/index.html' => 'x', 'Top/about.html' => 'y'])))
        ->toMatchArray(['is_web' => true, 'prefix' => 'Top/', 'entry' => 'index.html']);
});

it('keeps a zip with two candidate html entries and no index.html a document', function () {
    expect(AssetHelper::classifyFile('application/zip', 'zip', makeZip(['a.html' => 'x', 'b.html' => 'y'])))->toBe('document');
});

it('keeps a zip without index.html a document', function () {
    expect(AssetHelper::classifyFile('application/zip', 'zip', makeZip(['readme.txt' => 'x', 'a/b.html' => 'x'])))->toBe('document');
});

it('rejects a web bundle carrying a server-side entry', function () {
    $zip = makeZip(['index.html' => 'x', 'shell.php' => '<?php']);
    expect(app(WebPageInspector::class)->inspect($zip)['forbidden_entry'])->toBe('shell.php')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $zip))->toBeTrue();
});

it('does not apply the entry deny-list to js and css inside a bundle', function () {
    $zip = makeZip(['index.html' => 'x', 'app.js' => '1', 'a.css' => '2', 'w.wasm' => '3']);
    expect(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $zip))->toBeFalse();
});

it('classifies nothing as web when the feature is disabled', function () {
    config(['dam.web_pages.enabled' => false]);
    expect(AssetHelper::classifyFile('text/html', 'html'))->toBe('document');
});

it('rejects a bundle with more entries than the limit allows', function () {
    config(['dam.web_pages.max_entries' => 2]);
    $zip = makeZip(['index.html' => 'x', 'a.css' => '1', 'b.css' => '2']);

    expect(app(WebPageInspector::class)->inspect($zip)['reason'])->toBe('too_many_entries')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $zip))->toBeTrue();
});

it('rejects a bundle carrying an unsafe entry name', function () {
    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', 'x');
    $zip->addFromString('a/../../escape.css', '1');
    $zip->close();

    expect(app(WebPageInspector::class)->inspect($path)['reason'])->toBe('unsafe_path')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $path))->toBeTrue();
});

it('rejects a bundle whose entry is a symlink', function () {
    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '../../etc/passwd');
    $zip->setExternalAttributesName('index.html', ZipArchive::OPSYS_UNIX, 0xA1FF << 16);
    $zip->close();

    expect(app(WebPageInspector::class)->inspect($path)['reason'])->toBe('symlink_entry')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $path))->toBeTrue();
});

it('rejects a bundle whose compression ratio looks like a bomb', function () {
    $zip = makeZip(['index.html' => 'x', 'bomb.txt' => str_repeat("\0", 2 * 1024 * 1024)]);

    expect(app(WebPageInspector::class)->inspect($zip)['reason'])->toBe('zip-compression-suspicious')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $zip))->toBeTrue();
});

it('keeps a CHECK on file_type that admits the five known types and nothing else', function () {
    // The SQLite branch rebuilds the column with enum($types), so the CHECK must carry the new
    // list rather than a free-form varchar that would accept anything.
    foreach (['image', 'video', 'document', 'audio', 'web'] as $type) {
        expect(Asset::factory()->create(['file_type' => $type])->fresh()->file_type)->toBe($type);
    }

    $rejected = false;

    try {
        DB::table('dam_assets')->insert([
            'file_name' => 'bogus.bin', 'file_type' => 'bogus', 'file_size' => 1,
            'mime_type' => 'application/octet-stream', 'extension' => 'bin', 'path' => 'assets/Root/bogus.bin',
        ]);
    } catch (QueryException) {
        $rejected = true;
    }

    expect($rejected)->toBeTrue();
})->skip(fn () => ! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true), 'The CHECK constraint exists only on SQLite and PostgreSQL.');

it('rejects a bundle whose single entry exceeds the per-entry cap while the whole stays under the bundle cap', function () {
    config(['dam.import_bundle.max_entry_size' => 16, 'dam.web_pages.max_bundle_size' => 536870912]);
    $zip = makeZip(['index.html' => '<h1>x</h1>', 'big.css' => str_repeat('a', 64)]);

    expect(app(WebPageInspector::class)->inspect($zip)['reason'])->toBe('entry_too_large')
        ->and(AssetHelper::isForbiddenFile('zip', 'application/zip', 'site.zip', $zip))->toBeTrue();
});

it('names the specific reason a web bundle was refused', function () {
    $entry = makeZip(['index.html' => 'x', 'shell.php' => '<?php']);
    config(['dam.web_pages.max_entries' => 2]);
    $many = makeZip(['index.html' => 'x', 'a.css' => '1', 'b.css' => '2']);
    config(['dam.web_pages.max_entries' => 5000]);

    $path = tempnam(sys_get_temp_dir(), 'wp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', 'x');
    $zip->addFromString('a/../../escape.css', '1');
    $zip->close();

    expect(AssetHelper::webBundleRefusal('zip', $entry))->toBe(trans('dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => 'shell.php']))
        ->and(AssetHelper::webBundleRefusal('zip', $path))->toBe(trans('dam::app.admin.dam.web-pages.refused.unsafe-path'))
        ->and(AssetHelper::webBundleRefusal('zip', makeZip(['index.html' => 'x'])))->toBeNull()
        ->and(AssetHelper::webBundleRefusal('png', $entry))->toBeNull();

    config(['dam.web_pages.max_entries' => 2]);
    expect(AssetHelper::webBundleRefusal('zip', $many))->toBe(trans('dam::app.admin.dam.web-pages.refused.too-many-entries', ['limit' => 2]));
});

it('answers a refused web bundle upload with 422 and its reason, not 500', function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $directory = Directory::factory()->create(['name' => 'Pages', 'parent_id' => null]);
    $bytes = file_get_contents(makeZip(['index.html' => 'x', 'shell.php' => '<?php']));

    $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->createWithContent('site.zip', $bytes)],
        'directory_id' => $directory->id,
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'code'    => 'forbidden_type',
            'message' => trans('dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => 'shell.php']),
            'reason'  => ['code' => 'forbidden_entry', 'message' => trans('dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => 'shell.php'])],
        ]);

    $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')],
        'directory_id' => $directory->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.not-allowed'))
        ->assertJsonPath('reason.code', 'forbidden_type');
});
