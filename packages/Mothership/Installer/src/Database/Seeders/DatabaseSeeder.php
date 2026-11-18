<?php

namespace Mothership\Installer\Database\Seeders;

use Illuminate\Database\Seeder;
use Mothership\AiAgent\Database\Seeders\AgenticPimConfigSeeder;
use Mothership\Installer\Database\Seeders\Attribute\DatabaseSeeder as AttributeSeeder;
use Mothership\Installer\Database\Seeders\Category\DatabaseSeeder as CategorySeeder;
use Mothership\Installer\Database\Seeders\Core\DatabaseSeeder as CoreSeeder;
use Mothership\Installer\Database\Seeders\User\DatabaseSeeder as UserSeeder;
use Mothership\MagicAI\Database\Seeders\MagicAiPromptSeeder;
use Mothership\MagicAI\Database\Seeders\MagicAISystemPromptSeeder;
use Mothership\Measurement\Database\Seeders\MeasurementFamilySeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @param  array  $parameters
     */
    public function run($parameters = []): void
    {
        $this->call(AttributeSeeder::class, false, ['parameters' => $parameters]);
        $this->call(CategorySeeder::class, false, ['parameters' => $parameters]);
        $this->call(CoreSeeder::class, false, ['parameters' => $parameters]);
        $this->call(MeasurementFamilySeeder::class, false, ['parameters' => $parameters]);
        $this->call(UserSeeder::class, false, ['parameters' => $parameters]);
        $this->call(MagicAiPromptSeeder::class, false, ['parameters' => $parameters]);
        $this->call(MagicAISystemPromptSeeder::class, false, ['parameters' => $parameters]);
        $this->call(AgenticPimConfigSeeder::class);
    }
}
