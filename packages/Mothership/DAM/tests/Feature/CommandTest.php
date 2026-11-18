<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\User\Models\Admin;

function mockCommandInput($command, array $inputs)
{
    $command->expectsQuestion('Enter your Email', $inputs['email']);
    $command->expectsQuestion('Enter your Password', $inputs['password']);
    $command->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', $inputs['migrateNew']);
    $command->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', $inputs['delete']);
}

beforeEach(function () {

    Storage::fake('private');
    Storage::fake('s3');
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('count')->andReturn(2);
    DB::shouldReceive('limit')->andReturnSelf();
    DB::shouldReceive('offset')->andReturnSelf();
    DB::shouldReceive('get')->andReturn(collect([
        (object) ['id' => 1, 'path' => 'foo/bar1.jpg'],
        (object) ['id' => 2, 'path' => 'foo/bar2.jpg'],
    ]));
});

it('denies access for invalid credentials', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(false);

    $this->artisan('mothership:dam:move-asset-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'wrongpass')
        ->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', 'no')
        ->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', 'no')
        ->expectsOutput('Access Denied : Invalid Credentials.')
        ->assertExitCode(0);
});

it('moves a FileObject blob to s3 and updates its disk column', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    $bytes = 'file-object-blob-content';
    $sha256 = hash('sha256', $bytes);
    $objectPath = app(ContentStore::class)->objectPath($sha256);
    Storage::disk('private')->put($objectPath, $bytes);
    $fileObject = FileObject::factory()->create([
        'sha256' => $sha256,
        'size'   => strlen($bytes),
        'disk'   => 'private',
    ]);

    $this->artisan('mothership:dam:move-asset-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', 'no')
        ->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists($objectPath);
    expect(Storage::disk('s3')->get($objectPath))->toBe($bytes);
    expect($fileObject->fresh()->disk)->toBe('s3');
});

it('retries a FileObject blob whose s3 copy exists but is short (a previous partial upload)', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    $bytes = 'full-content-that-was-only-partially-uploaded-before';
    $sha256 = hash('sha256', $bytes);
    $objectPath = app(ContentStore::class)->objectPath($sha256);
    Storage::disk('private')->put($objectPath, $bytes);
    Storage::disk('s3')->put($objectPath, substr($bytes, 0, 5));
    $fileObject = FileObject::factory()->create([
        'sha256' => $sha256,
        'size'   => strlen($bytes),
        'disk'   => 'private',
    ]);

    $this->artisan('mothership:dam:move-asset-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', 'no')
        ->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    expect(Storage::disk('s3')->get($objectPath))->toBe($bytes);
    expect($fileObject->fresh()->disk)->toBe('s3');
});

it('deletes a FileObject local blob only after verifying the s3 copy when the delete option is yes', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    $bytes = 'delete-after-verify-content';
    $sha256 = hash('sha256', $bytes);
    $objectPath = app(ContentStore::class)->objectPath($sha256);
    Storage::disk('private')->put($objectPath, $bytes);
    $fileObject = FileObject::factory()->create([
        'sha256' => $sha256,
        'size'   => strlen($bytes),
        'disk'   => 'private',
    ]);

    $this->artisan('mothership:dam:move-asset-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to migrate only new uploaded files from your local to s3 (yes/no)', 'no')
        ->expectsQuestion('Want to delete files from local once uploaded to s3? (yes/no)', 'yes')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists($objectPath);
    Storage::disk('private')->assertMissing($objectPath);
    expect($fileObject->fresh()->disk)->toBe('s3');
});
