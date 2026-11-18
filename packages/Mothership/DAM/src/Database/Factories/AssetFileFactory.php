<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\FileObject;

class AssetFileFactory extends Factory
{
    protected $model = AssetFile::class;

    public function definition(): array
    {
        return [
            'dam_asset_id'   => Asset::factory(),
            'file_object_id' => FileObject::factory(),
            'role'           => AssetFile::ROLE_ORIGINAL,
            'label'          => null,
            'sort'           => 0,
        ];
    }
}
