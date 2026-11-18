<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

class CaptureProfile extends Model
{
    protected $table = 'dam_capture_profiles';

    protected $fillable = ['name', 'extensions', 'priority', 'enabled'];

    protected $casts = ['extensions' => 'array', 'priority' => 'array', 'enabled' => 'boolean'];
}
