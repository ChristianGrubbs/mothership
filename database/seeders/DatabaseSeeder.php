<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mothership\Installer\Database\Seeders\DatabaseSeeder as MothershipDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(MothershipDatabaseSeeder::class);
    }
}
