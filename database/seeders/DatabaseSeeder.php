<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the demo salon (ISSUE-26).
     *
     * Model events stay on (no WithoutModelEvents), so Booking still checks
     * every status transition the demo data makes.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
