<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;

class FileObjectFactory extends Factory
{
    protected $model = FileObject::class;

    public function definition(): array
    {
        return [
            'sha256'       => hash('sha256', $this->faker->unique()->uuid()),
            'size'         => $this->faker->numberBetween(1000, 5000000),
            'disk'         => 'private',
            // A locator is always the row's own blob (ADR 0009), so it follows an overridden sha256.
            'path'         => fn (array $attributes): string => app(ContentStore::class)->objectPath($attributes['sha256']),
            'mime_type'    => 'application/octet-stream',
            'sniffed_type' => null,
            'technical'    => null,
        ];
    }
}
