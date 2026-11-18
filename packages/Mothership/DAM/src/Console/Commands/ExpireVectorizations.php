<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Vectorization\Requests;

class ExpireVectorizations extends Command
{
    protected $signature = 'dam:vectorizations:expire';

    protected $description = 'Release abandoned vectorization reservations and remove failed-job files';

    public function handle(Requests $requests): int
    {
        DB::table('dam_vectorizations')
            ->where(function ($query) {
                $query->where(fn ($candidate) => $candidate
                    ->where('status', 'processing')
                    ->where('updated_at', '<', now()->subMinutes(15)))
                    ->orWhere(fn ($candidate) => $candidate
                        ->where('status', 'queued')
                        ->where('created_at', '<', now()->subMinutes(30)));
            })
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($requests) {
                foreach ($rows as $row) {
                    $requests->finish(id: $row->id, success: false, code: 'expired');
                }
            });

        // Recheck state before cleanup; never delete a completed conversion.
        DB::table('dam_vectorizations')
            ->where('status', 'failed')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    Storage::disk('private')->delete($row->input_path);
                    Storage::disk('private')->deleteDirectory('vectorizations/results/'.$row->id);
                }
            });

        return self::SUCCESS;
    }
}
