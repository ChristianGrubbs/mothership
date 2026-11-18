<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Mothership\DAM\Services\DamConfigurationFlags;

class DamConfiguration extends Model
{
    public $timestamps = false;

    protected $table = 'dam_configuration';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** A saved or deleted row can change what DamConfigurationFlags caches for every DAM request. */
    protected static function booted(): void
    {
        $forget = static fn () => DamConfigurationFlags::forget();

        static::saved($forget);
        static::deleted($forget);
    }

    public const KEY_MAP = [
        'DAM_TREE_SHOW_ASSETS'           => 'dam.tree.show_assets',
        'DAM_EXPLORER_ENABLED'           => 'dam.explorer.enabled',
        'DAM_EXPLORER_BOOKMARKS_ENABLED' => 'dam.explorer.bookmarks_enabled',
        'DAM_EXPLORER_SHOW_TREE'         => 'dam.explorer.show_tree',
    ];
}
