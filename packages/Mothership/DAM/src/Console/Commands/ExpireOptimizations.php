<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Optimization\Requests;

class ExpireOptimizations extends Command
{
    protected $signature = 'dam:optimizations:expire';

    protected $description = 'Settle abandoned optimization jobs';

    public function handle(Requests $requests): int
    {
        // Unlike vectorization, an optimization job never stages its result on a shared disk keyed by
        // this row's id: Optimizer::optimize() stages to a private system-temp directory the job itself
        // deletes in its own finally block, and a settled "optimized" row is streamed straight into the
        // content-addressed store by AssetOutputs::publish(). There is nothing left here to sweep.
        DB::table('dam_optimizations')
            ->where(function ($query) {
                $query->where(fn ($candidate) => $candidate
                    ->where('status', 'processing')
                    ->where('updated_at', '<', now()->subMinutes(20)))
                    ->orWhere(fn ($candidate) => $candidate
                        ->where('status', 'queued')
                        ->where('created_at', '<', now()->subMinutes(30)));
            })
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($requests) {
                foreach ($rows as $row) {
                    $requests->finish(id: $row->id, success: false, error: 'expired');
                }
            });

        return self::SUCCESS;
    }
}
