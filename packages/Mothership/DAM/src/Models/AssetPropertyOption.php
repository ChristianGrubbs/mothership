<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

/** One chosen option of a multi-select field value (P4a). */
class AssetPropertyOption extends Model
{
    public $timestamps = false;

    protected $table = 'dam_asset_property_options';

    protected $fillable = ['asset_property_id', 'value', 'sort'];

    protected $casts = ['sort' => 'integer'];
}
