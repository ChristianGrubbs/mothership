<?php

namespace Mothership\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\Core\Models\CoreConfig;

/**
 * @extends Factory<CoreConfig>
 */
class CoreConfigFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CoreConfig::class;

    /**
     * Define the model's default state.
     *
     * @throws \Exception
     */
    public function definition(): array
    {
        return [
            'channel_code' => 'default',
        ];
    }
}
