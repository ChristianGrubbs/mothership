<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\StorageIntegrity;

class ReclaimLibraryOrphans extends Command
{
    protected $signature = 'dam:storage:orphans
        {--force : Delete every orphan whose bytes an intact blob holds, then remove empty folder directories (without it, a read-only report)}';

    protected $description = 'Report files under assets/ that no asset names (pre-slice-3 leftovers) and, with --force, delete the ones a blob already holds (ADR 0009)';

    public function handle(StorageIntegrity $integrity): int
    {
        $force = (bool) $this->option('force');
        $result = $integrity->libraryOrphans(force: $force);

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

        // An orphan no intact blob holds may be the only copy of its bytes: it is never deleted here. Nor is a
        // name that is not valid UTF-8, which no row can name; it is printed percent-encoded, byte for byte.
        foreach ($result['files'] as $file) {
            if ($file['action'] === 'kept_invalid_utf8') {
                $this->warn("Name is not valid UTF-8; never deleted, review by hand: {$file['encoded_path']} (".($file['size'] ?? '?').' bytes)');
            } elseif (! $file['blob_backed']) {
                $this->warn("No blob holds these bytes; review by hand: {$file['path']} (".($file['size'] ?? '?').' bytes)');
            } elseif ($file['action'] === 'kept_unlink_failed') {
                $this->warn("Could not delete; nothing was queued, rerun --force: {$file['path']}");
            }
        }

        if (! $force && $result['blob_backed']['count'] > 0) {
            $this->warn("Dry run: nothing was changed. Rerun with --force to delete the {$result['blob_backed']['count']} orphan(s) a blob already holds.");
        }

        $remaining = $result['orphans']['count'] - $result['deleted']['count'] + $result['invalid_utf8']['count'];

        return $remaining > 0 ? self::FAILURE : self::SUCCESS;
    }
}
