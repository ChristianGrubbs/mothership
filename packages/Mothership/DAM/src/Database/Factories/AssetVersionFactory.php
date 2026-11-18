<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetVersion;

class AssetVersionFactory extends Factory
{
    protected $model = AssetVersion::class;

    public function definition(): array
    {
        return [
            'dam_asset_id'          => Asset::factory(),
            'number'                => 1,
            'created_by'            => null,
            'note'                  => null,
            'primary_asset_file_id' => null,
        ];
    }
}
