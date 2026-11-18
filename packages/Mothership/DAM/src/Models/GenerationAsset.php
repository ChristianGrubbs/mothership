<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

class GenerationAsset extends Model
{
    protected $table = 'dam_generation_assets';

    protected $fillable = ['generation_id', 'asset_id', 'manifest', 'manifest_sha256'];

    protected $casts = ['manifest'=>'array'];
}
