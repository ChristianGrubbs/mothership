<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        // dam_assets.path is UNIQUE and derived from the file name, so a bare Faker name collides by
        // chance once a test creates a few hundred assets (MovePreviewTest's 500) or meets committed rows
        // another test left behind. The random suffix makes the default collision-free; a test that passes
        // its own file_name or path is unaffected.
        $fileName = fake()->name().' '.Str::lower(Str::random(8)).'.jpg';

        return [
            'file_name'     => $fileName,
            'file_type'     => 'image',
            'file_size'     => fake()->numberBetween(10000, 5000000),
            'mime_type'     => 'image/jpg',
            'extension'     => 'jpg',
            'path'          => 'assets/Root/'.$fileName,
            'panorama_mode' => Asset::PANORAMA_AUTO,
        ];
    }
}
