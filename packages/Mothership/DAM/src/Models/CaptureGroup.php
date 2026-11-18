<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaptureGroup extends Model
{
    protected $table = 'dam_capture_groups';

    protected $fillable = ['profile_id', 'primary_asset_id', 'manual_primary'];

    protected $casts = ['manual_primary' => 'boolean'];

    public function members(): HasMany
    {
        return $this->hasMany(Asset::class, 'capture_group_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CaptureProfile::class, 'profile_id');
    }
}
