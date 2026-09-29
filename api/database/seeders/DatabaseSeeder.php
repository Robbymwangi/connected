<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with one realistic school.
     */
    public function run(): void
    {
        $this->call(SchoolSeeder::class);
    }
}
