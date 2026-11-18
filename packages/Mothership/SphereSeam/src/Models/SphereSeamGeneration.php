<?php

namespace Mothership\SphereSeam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SphereSeamGeneration extends Model
{
    use HasUuids;

    protected $table = 'sphereseam_generations';

    protected $guarded = [];

    protected $casts = [
        'estimated_credits'    => 'float',
        'started_at'           => 'datetime',
        'finished_at'          => 'datetime',
        'submission_ambiguous' => 'boolean',
    ];
}
