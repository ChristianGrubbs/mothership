<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetRelationship;

class AssetRelationshipFactory extends Factory
{
    protected $model = AssetRelationship::class;

    public function definition(): array
    {
        return [
            'from_asset_id' => Asset::factory(),
            'to_asset_id'   => Asset::factory(),
            'type'          => AssetRelationship::TYPE_RELATED_TO,
            'metadata'      => null,
            'created_by'    => null,
        ];
    }
}
