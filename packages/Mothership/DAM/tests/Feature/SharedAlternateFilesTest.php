<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;

/*
 * A shared link hands out the asset's alternate formats (P3a slots) beside its default file: the page lists
 * each with its label and metadata, and each download returns exactly the file it names, to a recipient who
 * is not signed in. A slot the link does not hand out answers 404, never the default file in its place.
 */

uses(DatabaseTransactions::class);

const SHARED_PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

beforeEach(function () {
    DB::table('dam_shares')->delete();
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();

    $this->folder = Directory::create(['name' => 'Alternates '.uniqid()]);
    $this->asset = Asset::factory()->create(['file_name' => 'logo.png', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => 'assets/Alternates/logo.png']);
    $this->asset->directories()->attach($this->folder->id);
    Storage::disk(Directory::getAssetDisk())->put($this->asset->path, 'DEFAULT-PNG-BYTES');
    $this->asset->markGraphContentChanged()->save();
});

/** Attaches a file to an asset (the test's own by default) through the Files tab endpoint, as an editor would. */
function attachSharedFile(object $test, string $name, string $content, string $role = AssetFile::ROLE_ALTERNATE, ?string $label = null, ?Asset $asset = null): array
{
    return $test->postJson('/'.config('app.admin_url').'/dam/assets/'.($asset ?? $test->asset)->id.'/files', array_filter([
        'file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => $role, 'label' => $label,
    ]))->assertCreated()->json();
}

/** The downloaded bytes of a local-disk delivery. */
function downloadedBytes($response): string
{
    return $response->baseResponse->getFile()->getContent();
}

function signOutShareRecipient(): void
{
    Auth::guard('admin')->logout();
    expect(Auth::guard('admin')->check())->toBeFalse();
}

it('lists the main file and each stacked file with its label on an asset link', function () {
    $svg = attachSharedFile($this, 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', label: 'Vector logo.svg');
    $pdf = attachSharedFile($this, 'print.pdf', '%PDF-1.4 print');
    $main = DB::table('dam_attachments')->where('dam_asset_id', $this->asset->id)->where('is_representative', true)->value('id');
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $html = $this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent();

    expect($html)
        ->toContain('data-dam-share-files')
        ->toContain('data-dam-share-file="'.$main.'" data-dam-share-file-main="true"')
        ->toContain('data-dam-share-file="'.$svg['attachment_id'].'" data-dam-share-file-main="false"')
        ->toContain('data-dam-share-file="'.$pdf['attachment_id'].'"')
        ->toContain('Vector logo.svg')
        ->toContain('print.pdf')
        ->toContain(e(trans('dam::app.share.public.files.main')))
        ->toContain(e(trans('dam::app.share.public.files.download-main')))
        ->toContain('SVG')
        ->toContain(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $svg['attachment_id']]))
        ->toContain(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $pdf['attachment_id']]))
        ->toContain(route('dam.share.download', ['token' => $share->urlToken()]));
});

it('downloads exactly the alternate a signed-out recipient picks, and the default is unchanged', function () {
    $svg = attachSharedFile($this, 'logo.svg', 'SVG-BYTES', label: 'Vector logo.svg');
    $pdf = attachSharedFile($this, 'print.pdf', 'PDF-BYTES');
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $response = $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $svg['attachment_id']]))
        ->assertOk()->assertDownload('Vector logo.svg');
    expect(downloadedBytes($response))->toBe('SVG-BYTES');

    $response = $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $pdf['attachment_id']]))
        ->assertOk()->assertDownload('print.pdf');
    expect(downloadedBytes($response))->toBe('PDF-BYTES');

    $response = $this->get(route('dam.share.download', ['token' => $share->urlToken()]))->assertOk()->assertDownload('logo.png');
    expect(downloadedBytes($response))->toBe('DEFAULT-PNG-BYTES')
        ->and($share->fresh()->download_count)->toBe(3);
});

it('serves alternates of an asset inside a shared folder by the folder link', function () {
    $svg = attachSharedFile($this, 'logo.svg', 'SVG-BYTES');
    $share = Share::factory()->forDirectory($this->folder->id)->create();
    signOutShareRecipient();

    $html = $this->get(route('dam.share.asset_view', ['token' => $share->urlToken(), 'assetId' => $this->asset->id]))->assertOk()->getContent();
    $url = route('dam.share.asset_file_download', ['token' => $share->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $svg['attachment_id']]);
    expect($html)->toContain($url);

    $response = $this->get($url)->assertOk()->assertDownload('logo.svg');
    expect(downloadedBytes($response))->toBe('SVG-BYTES');
});

it('serves every stacked working file and the main slot, and answers 404 for a generated slot or an unknown id', function () {
    // OD5 (2026-10-19): a link hands out every stacked file, not only alternates; generated files stay private.
    $stacked = collect([AssetFile::ROLE_SIDECAR, AssetFile::ROLE_SOURCE, AssetFile::ROLE_PROJECT, AssetFile::ROLE_MASK])
        ->map(fn (string $role) => attachSharedFile($this, 'work.'.$role, 'STACKED-'.$role, $role)['attachment_id']);
    $generated = collect([AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL])
        ->map(fn (string $role) => attachSharedFile($this, 'made.'.$role, 'MADE-'.$role, $role)['attachment_id']);
    $representative = DB::table('dam_attachments')->where('dam_asset_id', $this->asset->id)->where('is_representative', true)->value('id');
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    foreach ([...$stacked, $representative] as $attachment) {
        $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $attachment]))->assertOk();
    }

    // A generated slot and an unknown id; then an id past the route's bound.
    foreach ([...$generated, 999999] as $attachment) {
        $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $attachment]))->assertNotFound();
    }
    $this->get('/share/'.$share->urlToken().'/download/99999999999999999999')->assertNotFound();

    expect($this->get(route('dam.share.show', $share->urlToken()))->getContent())
        ->toContain('work.sidecar')
        ->toContain('data-dam-share-files')
        ->not->toContain('made.proxy');
    expect($share->fresh()->download_count)->toBe(5);
});

it('never serves another asset\'s alternate through this asset\'s link or this folder\'s link', function () {
    $other = Asset::factory()->create(['file_name' => 'other.png', 'path' => 'assets/Alternates/other.png']);
    $other->directories()->attach($this->folder->id);
    $theirs = attachSharedFile($this, 'theirs.svg', 'THEIR-SVG', asset: $other);
    $ownLink = Share::factory()->forAsset($other->id)->create();
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    signOutShareRecipient();

    // The slot is readable: its own asset's link serves it.
    expect(downloadedBytes($this->get(route('dam.share.file_download', ['token' => $ownLink->urlToken(), 'attachment' => $theirs['attachment_id']]))->assertOk()))->toBe('THEIR-SVG');

    $this->get(route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $theirs['attachment_id']]))->assertNotFound();
    // Both assets are in the shared folder, but the slot belongs to the other one.
    $this->get(route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $theirs['attachment_id']]))->assertNotFound();

    expect($assetLink->fresh()->download_count)->toBe(0)
        ->and($folderLink->fresh()->download_count)->toBe(0);
});

it('escapes a hostile label everywhere the page shows it', function () {
    $hostile = 'x" autofocus onfocus="alert(1)';
    attachSharedFile($this, 'logo.svg', 'SVG', label: $hostile);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    signOutShareRecipient();

    foreach ([route('dam.share.show', $assetLink->urlToken()), route('dam.share.asset_view', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id])] as $page) {
        expect($this->get($page)->assertOk()->getContent())
            ->not->toContain('autofocus onfocus="alert(1)')
            ->toContain(e('x" autofocus onfocus="alert(1)'));
    }
});

it('keeps a labelled alternate\'s own suffix even when its sniffed type is generic', function () {
    // Real bytes, so the type is sniffed as the server sniffs it: an Office file is a zip, an Illustrator file a PDF.
    $real = function (string $name, callable $write): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'dam_share_alt_');
        $write($path);

        return new UploadedFile($path, $name, null, null, true);
    };
    $docx = $real('Brief.docx', function (string $path): void {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();
    });
    $ai = $real('Logo.ai', fn (string $path) => file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"));
    expect($docx->getMimeType())->toBe('application/zip')->and($ai->getMimeType())->toBe('application/pdf');

    $files = collect([$docx, $ai])->map(fn (UploadedFile $file) => $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files', [
        'file' => $file, 'role' => AssetFile::ROLE_ALTERNATE,
    ])->assertCreated()->json());
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $files[0]['attachment_id']]))->assertOk()->assertDownload('Brief.docx');
    $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $files[1]['attachment_id']]))->assertOk()->assertDownload('Logo.ai');

    // The type shown beside each name is the one it downloads as.
    $html = $this->get(route('dam.share.show', $share->urlToken()))->getContent();
    foreach ([[$files[0]['attachment_id'], 'DOCX'], [$files[1]['attachment_id'], 'AI']] as [$attachment, $type]) {
        expect(preg_match('/data-dam-share-file="'.$attachment.'"[^>]*>(.*?)<\/li>/s', $html, $row))->toBe(1)
            ->and(preg_match('/data-dam-share-file-ext[^>]*>(.*?)</s', $row[1], $ext))->toBe(1)
            ->and(trim($ext[1]))->toBe($type);
    }
});

it('gives a label with no suffix (or a bare trailing dot) the file\'s own extension', function () {
    $file = attachSharedFile($this, 'logo.svg', 'SVG', label: 'Vector logo.');
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $file['attachment_id']]))
        ->assertOk()->assertDownload('Vector logo.svg');
});

it('names an alternate whose label was cleared after the asset, by the alternate\'s own type, on both links', function (string $assetName, string $upload, string $content, string $expectedName, ?string $expectedType) {
    $this->asset->update(['file_name' => $assetName]);
    $alt = attachSharedFile($this, $upload, $content);
    $this->patchJson(route('admin.dam.assets.attachments.update', ['asset' => $this->asset->id, 'attachment' => $alt['attachment_id']]), ['label' => ''])
        ->assertOk();
    $file = AssetFile::find($alt['id']);
    expect(DB::table('dam_attachments')->where('id', $alt['attachment_id'])->value('label'))->toBeNull()
        ->and($file->label)->toBeNull()
        ->and($file->extension())->toBe($expectedType ?? '');
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    signOutShareRecipient();

    // The row shows the type the file downloads as (none when unresolved), then its size.
    $links = [
        [route('dam.share.show', $assetLink->urlToken()), route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $alt['attachment_id']])],
        [route('dam.share.asset_view', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id]), route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $alt['attachment_id']])],
    ];

    foreach ($links as [$page, $download]) {
        $html = $this->get($page)->assertOk()->getContent();
        expect(preg_match('/data-dam-share-file="'.$alt['attachment_id'].'"[^>]*>(.*?)<\/li>/s', $html, $row))->toBe(1)
            ->and(preg_match('/<p class="truncate[^"]*"[^>]*>(.*?)<\/p>/s', $row[1], $name))->toBe(1)
            ->and(html_entity_decode(trim($name[1])))->toBe($expectedName)
            ->and(preg_match('/data-dam-share-file-ext[^>]*>(.*?)</s', $row[1], $type))->toBe($expectedType ? 1 : 0)
            ->and(preg_match('/data-dam-share-file-size[^>]*>(.*?)</s', $row[1], $size))->toBe(1)
            ->and(trim($size[1]))->toBe(Number::fileSize(strlen($content), precision: 1));

        if ($expectedType) {
            expect(trim($type[1]))->toBe(strtoupper($expectedType));
        }

        $response = $this->get($download)->assertOk()->assertDownload($expectedName);
        expect(downloadedBytes($response))->toBe($content);
    }
})->with([
    'a name with no suffix'        => ['logo', 'print.pdf', SHARED_PDF_BYTES, 'logo.pdf', 'pdf'],
    'a digit run, not a suffix'    => ['logo.2026', 'print.pdf', SHARED_PDF_BYTES, 'logo.2026.pdf', 'pdf'],
    'a dotted stem'                => ['logo.v2.png', 'print.pdf', SHARED_PDF_BYTES, 'logo.v2.pdf', 'pdf'],
    'an uppercase suffix'          => ['logo.PNG', 'print.pdf', SHARED_PDF_BYTES, 'logo.pdf', 'pdf'],
    'a falsy stem'                 => ['0.png', 'print.pdf', SHARED_PDF_BYTES, '0.pdf', 'pdf'],
    'an unknown type keeps a stem' => ['logo.v2.png', 'blob.bin', "\x00\x01\x02\x03\xFF\xFE\x00opaque\x00", 'logo.v2', null],
]);

it('refuses an alternate through an expired link, a revoked link, or a folder link the asset is outside', function () {
    $svg = attachSharedFile($this, 'logo.svg', 'SVG-BYTES');
    $expired = Share::factory()->forAsset($this->asset->id)->expired()->create();
    $revoked = Share::factory()->forAsset($this->asset->id)->revoked()->create();
    $elsewhere = Share::factory()->forDirectory(Directory::create(['name' => 'Elsewhere '.uniqid()])->id)->create();
    signOutShareRecipient();

    $this->get(route('dam.share.file_download', ['token' => $expired->urlToken(), 'attachment' => $svg['attachment_id']]))->assertStatus(410);
    $this->get(route('dam.share.file_download', ['token' => $revoked->urlToken(), 'attachment' => $svg['attachment_id']]))->assertStatus(410);
    $this->get(route('dam.share.asset_file_download', ['token' => $elsewhere->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $svg['attachment_id']]))->assertNotFound();
});

it('answers 404 without counting a download when the chosen alternate\'s bytes are gone', function () {
    $svg = attachSharedFile($this, 'logo.svg', 'SVG-BYTES');
    $share = Share::factory()->forAsset($this->asset->id)->create();
    $object = AssetFile::find($svg['id'])->fileObject;
    Storage::disk($object->disk)->delete($object->path);
    signOutShareRecipient();

    $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $svg['attachment_id']]))->assertNotFound();
    expect($share->fresh()->download_count)->toBe(0);
});

it('serves the current revision of an alternate, and names a label without a suffix by its type', function () {
    $alt = attachSharedFile($this, 'logo.svg', 'OLD-SVG', label: 'Vector logo');
    $current = AssetFile::find($alt['id']);
    $newer = attachSharedFile($this, 'logo-v2.svg', 'NEW-SVG');
    // Move the newer upload into the first slot as its current revision, superseding the old one.
    AssetFile::whereKey($current->id)->update(['is_current' => false, 'revision_number' => 1]);
    AssetFile::whereKey($newer['id'])->update(['attachment_id' => $alt['attachment_id'], 'is_current' => true, 'revision_number' => 2, 'label' => 'Vector logo']);
    DB::table('dam_attachments')->where('id', $newer['attachment_id'])->delete();
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $response = $this->get(route('dam.share.file_download', ['token' => $share->urlToken(), 'attachment' => $alt['attachment_id']]))
        ->assertOk()->assertDownload('Vector logo.svg');
    expect(downloadedBytes($response))->toBe('NEW-SVG');
});

it('keeps a single-file page without a file list', function () {
    $share = Share::factory()->forAsset($this->asset->id)->create();
    signOutShareRecipient();

    $html = $this->get(route('dam.share.show', $share->urlToken()))->assertOk()->getContent();

    expect($html)->toContain('<v-zoomable-image')
        ->and($html)->toContain('>'.e(trans('dam::app.share.public.download')).'</span>')
        ->and($html)->not->toContain('data-dam-share-files>')
        ->and($html)->not->toContain('data-dam-share-files-strip aria-label')
        ->and($html)->not->toContain(e(trans('dam::app.share.public.files.download-main')));
});

/*
 * Codex review of the S4 + #144 + main merge (a1ff2db1, m1): the alternate routes answer an asset's lifecycle exactly
 * as the default download does (P2 S3/S4). An asset link answers 404 for a draft or pending asset, 410 for one archived,
 * outside its window or in the trash; a folder link leaves such an asset out (404). Nothing is counted, and the
 * alternate is back once the asset is.
 */
it('answers an alternate by the asset\'s lifecycle on asset and folder links, counts nothing, and serves it again once restored', function () {
    $svg = attachSharedFile($this, 'logo.svg', 'SVG-BYTES');
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    $viaAsset = fn () => $this->get(route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $svg['attachment_id']]));
    $viaFolder = fn () => $this->get(route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $svg['attachment_id']]));
    $states = [
        'draft'     => [['availability' => Asset::AVAILABILITY_DRAFT], 404],
        'pending'   => [['availability' => Asset::AVAILABILITY_PENDING_REVIEW], 404],
        'archived'  => [['availability' => Asset::AVAILABILITY_ARCHIVED], 410],
        'not yet'   => [['available_from' => now()->addDay()], 410],
        'expired'   => [['available_until' => now()->subMinute()], 410],
    ];
    $available = ['availability' => Asset::AVAILABILITY_AVAILABLE, 'available_from' => null, 'available_until' => null];
    signOutShareRecipient();

    foreach ($states as $case => [$state, $assetStatus]) {
        $this->asset->update(array_merge($available, $state));
        expect($viaAsset()->getStatusCode())->toBe($assetStatus, "asset link, {$case}")
            ->and($viaFolder()->getStatusCode())->toBe(404, "folder link, {$case}");
    }

    $this->asset->update($available);
    $this->asset->delete();
    expect($viaAsset()->getStatusCode())->toBe(410, 'asset link, trashed')
        ->and($viaFolder()->getStatusCode())->toBe(404, 'folder link, trashed')
        ->and($assetLink->fresh()->download_count)->toBe(0)
        ->and($folderLink->fresh()->download_count)->toBe(0);

    $this->asset->restore();
    expect(downloadedBytes($viaAsset()->assertOk()))->toBe('SVG-BYTES')
        ->and(downloadedBytes($viaFolder()->assertOk()))->toBe('SVG-BYTES');
});

it('sends a hostile stored label as one safe attachment header on both links', function (string $label) {
    $alt = attachSharedFile($this, 'logo.svg', 'SVG-BYTES', label: 'Vector logo.svg');
    // Stored as-is, past any editor validation, so the header is what keeps the download safe.
    DB::table('dam_attachments')->where('id', $alt['attachment_id'])->update(['label' => $label]);
    DB::table('dam_asset_files')->where('attachment_id', $alt['attachment_id'])->update(['label' => $label]);
    $assetLink = Share::factory()->forAsset($this->asset->id)->create();
    $folderLink = Share::factory()->forDirectory($this->folder->id)->create();
    signOutShareRecipient();

    foreach ([
        route('dam.share.file_download', ['token' => $assetLink->urlToken(), 'attachment' => $alt['attachment_id']]),
        route('dam.share.asset_file_download', ['token' => $folderLink->urlToken(), 'assetId' => $this->asset->id, 'attachment' => $alt['attachment_id']]),
    ] as $url) {
        $response = $this->get($url)->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');

        expect(downloadedBytes($response))->toBe('SVG-BYTES')
            ->and($disposition)->toStartWith('attachment;')
            ->not->toMatch('/[\r\n\x00]/')
            ->and($response->headers->has('X-Injected'))->toBeFalse();

        // The plain filename= fallback is printable ASCII with no path separator; any UTF-8 name rides in filename*.
        // RFC 6266: a quoted string (with \-escaped quotes) or a bare token.
        preg_match('/filename=("(?:[^"\\\\]|\\\\.)*"|[^;\s]+)/', $disposition, $plain);
        expect($plain[1] ?? null)->not->toBeNull();
        $plain[1] = str_starts_with($plain[1], '"') ? stripcslashes(substr($plain[1], 1, -1)) : $plain[1];
        expect($plain[1])->toMatch('/^[\x20-\x7e]+$/')
            ->not->toContain('/')
            ->not->toContain('\\');
    }
})->with([
    'quotes'           => ['Say "hi".svg'],
    'header splitting' => ["Logo\r\nX-Injected: 1.svg"],
    'unicode'          => ['Café ☕ logo.svg'],
    'percent'          => ['100% logo.svg'],
    'path separators'  => ['../..\\evil/logo.svg'],
]);
