<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Mothership\DAM\Contracts\AssetVersion as AssetVersionContract;
use Mothership\DAM\Database\Factories\AssetVersionFactory;

class AssetVersion extends Model implements AssetVersionContract
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'dam_asset_versions';

    protected $fillable = [
        'dam_asset_id',
        'number',
        'created_by',
        'note',
        'primary_asset_file_id',
    ];

    protected $casts = [
        'number' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            if ($version->primary_asset_file_id === null) {
                return;
            }

            $ownsFile = AssetFile::query()
                ->whereKey($version->primary_asset_file_id)
                ->where('dam_asset_id', $version->dam_asset_id)
                ->exists();

            if (! $ownsFile) {
                throw new InvalidArgumentException('An asset version primary file must belong to the same asset.');
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function primaryAssetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'primary_asset_file_id');
    }

    protected static function newFactory(): Factory
    {
        return AssetVersionFactory::new();
    }
}
