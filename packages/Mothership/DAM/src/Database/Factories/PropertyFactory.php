<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;

class PropertyFactory extends Factory
{
    protected $model = AssetProperty::class;

    public function definition(): array
    {
        return [
            'name'         => $this->faker->words(2, true),
            'type'         => 'Text',
            'value'        => $this->faker->sentence(),
            'language'     => 'English',
            'dam_asset_id' => Asset::factory(),
        ];
    }
}
