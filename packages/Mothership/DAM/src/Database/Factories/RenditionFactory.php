<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Rendition;

class RenditionFactory extends Factory
{
    protected $model = Rendition::class;

    public function definition(): array
    {
        return [
            'dam_asset_id'          => Asset::factory(),
            'source_asset_file_id'  => null,
            'recipe'                => 'thumbnail-default',
            'fingerprint'           => null,
            'parameters'            => [],
            'worker'                => null,
            'worker_version'        => null,
            'status'                => Rendition::STATUS_QUEUED,
            'progress'              => 0,
            'cancel_requested_at'   => null,
            'started_at'            => null,
            'finished_at'           => null,
            'error'                 => null,
            'output_file_object_id' => null,
        ];
    }
}
