<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\FileObject;
use Mothership\User\Models\Admin;
use Symfony\Component\Console\Helper\ProgressBar;

class MoveDamAssetsToS3 extends Command
{
    protected $signature = 'mothership:dam:move-asset-to-s3';

    protected $description = 'Move DAM asset files to AWS S3 if they exist locally';

    protected $limit = 1000;

    public function handle()
    {
        $this->authCheck();
    }

    public function authCheck()
    {
        $email = $this->ask('Enter your Email');
        $password = $this->secret('Enter your Password');
        $migrateNew = false;
        $migrateNewRes = $this->ask('Want to migrate only new uploaded files from your local to s3 (yes/no)');

        if (in_array(strtolower($migrateNewRes), ['yes', 'y'])) {
            $migrateNew = true;
        }

        $this->info('Migrate New only flag: '.($migrateNew ? 'Yes' : 'No'));

        $delete = false;
        $userRes = $this->ask('Want to delete files from local once uploaded to s3? (yes/no)');

        if (in_array(strtolower($userRes), ['yes', 'y'])) {
            $delete = true;
        }

        $this->info('Delete flag: '.($delete ? 'Yes' : 'No'));

        $admin = Admin::where('email', $email)->first();

        if ($admin && Hash::check($password, $admin->password)) {
            $this->moveAssetsTos3($delete, $migrateNew);
        } else {
            $this->info('Access Denied : Invalid Credentials.');
        }
    }

    public function moveAssetsTos3(bool $delete, bool $migrateNew)
    {
        $offset = 0;
        $this->info('Starting migration to aws');

        $totalAssetCount = DB::table('dam_assets')->count();
        $progressBar = new ProgressBar($this->output, $totalAssetCount);
        $progressBar->start();
        $logs = [];

        // dam_assets.path names no file (ADR 0009 slice 3): only cover art, an id-keyed derivative
        // cache outside assets/ (Decision 13, unaffected by this slice), still needs a per-asset walk.
        // FileObject blobs are migrated separately below, by migrateFileObjectBlobs().
        do {
            $assets = DB::table('dam_assets')
                ->limit($this->limit)
                ->offset($offset)
                ->get();

            if ($assets->isEmpty()) {
                break;
            }

            foreach ($assets as $asset) {
                $this->migrateCoverArt($asset, $delete, $logs, $migrateNew);
                $progressBar->advance();
            }
            $offset += $this->limit;
        } while ($assets->count() === $this->limit);
        $progressBar->finish();
        $this->newLine();

        $this->migrateFileObjectBlobs($delete, $logs);

        if ($delete) {
            $this->info('Files Deleted from your local Private Disk Successfully!!.');
        }
        Log::info('User data: '.json_encode($logs));
        $this->info('Done Moving DAM Assets.');
    }

    /**
     * The loop above only moves cover art. FileObjects are the content-addressed blob store (ADR
     * 0009): every original/version a FileObject names still lives on the local disk until its own
     * row is migrated, so this pass copies each one's blob to S3 under the same key (content-addressed
     * locators never change) and repoints its `disk`.
     * Uses Eloquent rather than the DB facade so it is unaffected by the `dam_assets`-shaped
     * DB::table() mocks other callers of this command may have configured in tests.
     */
    protected function migrateFileObjectBlobs(bool $delete, array &$logs): void
    {
        $totalFileObjectCount = FileObject::query()->where('disk', 'private')->count();

        if ($totalFileObjectCount === 0) {
            return;
        }

        $this->info('Starting FileObject blob migration to aws');
        $progressBar = new ProgressBar($this->output, $totalFileObjectCount);
        $progressBar->start();

        $lastId = 0;

        do {
            $objects = FileObject::query()
                ->where('disk', 'private')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($this->limit)
                ->get();

            if ($objects->isEmpty()) {
                break;
            }

            foreach ($objects as $object) {
                $lastId = $object->id;
                $this->migrateFileObjectBlob($object, $delete, $logs);
                $progressBar->advance();
            }
        } while ($objects->count() === $this->limit);

        $progressBar->finish();
        $this->newLine();
    }

    protected function migrateFileObjectBlob(FileObject $object, bool $delete, array &$logs): void
    {
        $path = (string) $object->path;
        $expectedSize = (int) $object->size;

        if ($path === '') {
            $logs[] = "No blob path for FileObject ID: ({$object->id})";

            return;
        }

        if (! Storage::disk('private')->exists($path)) {
            $logs[] = "FileObject blob not found for FileObject ID {$object->id}: $path";

            return;
        }

        // Missing or short (a partial earlier upload) both need a fresh copy; only a size match
        // proves the object made it over intact, so a previous partial upload is retried instead
        // of being logged as a mismatch forever.
        $needsCopy = ! Storage::disk('s3')->exists($path)
            || (int) Storage::disk('s3')->size($path) !== $expectedSize;

        if ($needsCopy) {
            // Blobs are multi-GB videos: stream disk-to-disk instead of loading the whole
            // object into PHP memory with get()/put().
            $stream = Storage::disk('private')->readStream($path);

            if (! is_resource($stream)) {
                $logs[] = "Unable to open a read stream for FileObject ID {$object->id}: $path";

                return;
            }

            try {
                Storage::disk('s3')->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $remoteSize = (int) Storage::disk('s3')->size($path);

        if ($remoteSize !== $expectedSize) {
            $logs[] = "S3 copy size mismatch for FileObject ID {$object->id}: expected {$expectedSize}, got {$remoteSize}";

            return;
        }

        $object->update(['disk' => 's3']);

        if ($delete) {
            Storage::disk('private')->delete($path);
        }

        $logs[] = "Moved FileObject blob ID {$object->id} to s3";
    }

    protected function migrateCoverArt($asset, bool $delete, array &$logs, bool $skipIfExists): void
    {
        $coverPath = $this->extractCoverArtPath($asset);
        if (! $coverPath) {
            return;
        }

        if (! Storage::disk('private')->exists($coverPath)) {
            $logs[] = "Cover art not found for asset ID {$asset->id}: $coverPath";

            return;
        }

        if ($skipIfExists && Storage::disk('s3')->exists($coverPath)) {
            $logs[] = "Cover art for asset ID {$asset->id} already exists on S3. Skipped.";

            return;
        }

        Storage::disk('s3')->put($coverPath, Storage::disk('private')->get($coverPath));

        if ($delete) {
            Storage::disk('private')->delete($coverPath);
        }

        $logs[] = "Moved cover art for asset ID {$asset->id} to s3";
    }

    protected function extractCoverArtPath($asset): ?string
    {
        $meta = $asset->meta_data ?? null;
        if (! $meta) {
            return null;
        }

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        if (! is_array($meta)) {
            return null;
        }

        $path = $meta['cover_art_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
