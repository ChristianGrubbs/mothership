<?php

/**
 * Fixtures shared by the Craft MCP and Hub resource tests (first written for the in-app Creative Hub, removed
 * 2026-11-17). Every test file that uses them require_once's this file itself, so tests/Pest.php does not change. No
 * real Library content: every byte is made here.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

const HUB_MEMBER_KEYS = ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.download', 'dam.asset.upload', 'dam.hub'];

if (! function_exists('hubEnable')) {
    function hubEnable(): void
    {
        config(['dam.hub.enabled' => true]);
    }
}

if (! function_exists('hubFolder')) {
    function hubFolder(string $name = 'Hub'): Directory
    {
        return Directory::factory()->create(['name' => $name.'-'.uniqid()]);
    }
}

if (! function_exists('hubAsset')) {
    /** An asset filed in $folder with made-up bytes: a real PNG/JPEG for those types, a tiny PDF or PSD header otherwise. */
    function hubAsset(Directory $folder, string $extension = 'png', ?string $fileName = null): Asset
    {
        $fileName ??= 'quadrants-'.uniqid().'.'.$extension;
        $path = Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath().'/'.$fileName;
        // Held in a variable: the fake file's temp path disappears once the object is released.
        $image = in_array($extension, ['png', 'jpg', 'jpeg'], true) ? UploadedFile::fake()->image('x.'.$extension, 8, 8) : null;
        $bytes = match ($extension) {
            'png', 'jpg', 'jpeg' => file_get_contents($image->getRealPath()),
            'psd'                => "8BPS\x00\x01".str_repeat("\x00", 26),
            default              => "%PDF-1.4\n% hub fixture\n",
        };
        Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
        $asset = Asset::factory()->create([
            'file_name' => $fileName,
            'path'      => $path,
            'extension' => $extension,
            'file_type' => in_array($extension, ['png', 'jpg', 'jpeg', 'psd'], true) ? 'image' : 'document',
            'mime_type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'psd' => 'image/vnd.adobe.photoshop', 'pdf' => 'application/pdf'][$extension] ?? 'application/octet-stream',
        ]);
        $folder->assets()->attach($asset->id);

        return $asset;
    }
}

if (! function_exists('hubMember')) {
    /** A custom-role admin holding $keys and granted $folder only (the `grants` model), signed in. */
    function hubMember(Directory $folder, array $keys = HUB_MEMBER_KEYS): Admin
    {
        $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => array_values($keys)]);
        DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(DirectoryPermissionService::class)->flush();
        $admin = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
        test()->actingAs($admin, 'admin');

        return $admin;
    }
}

if (! function_exists('hubCapture')) {
    /** What an app hands back: a layered-PSD header by default, or a real PNG for a same-type replace. */
    function hubCapture(string $name = 'quadrants.psd'): UploadedFile
    {
        return str_ends_with($name, '.png')
            ? UploadedFile::fake()->image($name, 16, 16)
            : UploadedFile::fake()->createWithContent($name, "8BPS\x00\x01".str_repeat("\x00", 26));
    }
}
