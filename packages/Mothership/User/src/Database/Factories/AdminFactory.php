<?php

namespace Mothership\User\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mothership\Core\Models\Locale;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Admin::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        // The installer's Administrator (full access) is the lowest role id. The query must be ordered:
        // an unordered first() on PostgreSQL returns heap order, and committed role updates in the DAM
        // access tests can move a custom demo role ahead of it, which 403s every later admin request.
        return [
            'name'         => fake()->name(),
            'email'        => fake()->unique()->email,
            'password'     => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role_id'      => Role::query()->orderBy('id')->first()?->id ?? Role::factory(),
            'status'       => 1,
            'ui_locale_id' => Locale::where('code', 'en_US')->first()?->id ?? Locale::factory()->state(['code' => 'en_US', 'status' => 1]),
            'image'        => null,
        ];
    }
}
