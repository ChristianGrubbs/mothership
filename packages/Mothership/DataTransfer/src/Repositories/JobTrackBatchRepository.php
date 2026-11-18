<?php

namespace Mothership\DataTransfer\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\DataTransfer\Contracts\JobTrackBatch;

class JobTrackBatchRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return JobTrackBatch::class;
    }
}
