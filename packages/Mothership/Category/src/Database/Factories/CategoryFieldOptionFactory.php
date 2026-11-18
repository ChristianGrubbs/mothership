<?php

namespace Mothership\Category\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\Category\Models\CategoryFieldOption;

/**
 * @extends Factory<CategoryFieldOption>
 */
class CategoryFieldOptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CategoryFieldOption::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'code'         => fake()->regexify('/^[a-zA-Z]+\w+$/'),
            'sort_order'   => fake()->randomDigit(),
        ];
    }
}
