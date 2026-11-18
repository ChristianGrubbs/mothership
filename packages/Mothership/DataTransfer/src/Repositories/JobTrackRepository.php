<?php

namespace Mothership\DataTransfer\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\DataTransfer\Contracts\JobTrack;

class JobTrackRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return JobTrack::class;
    }
}
