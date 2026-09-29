<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Demo data for development and staging (plan Phase 11) — never runs in
     * production; DatabaseSeeder guards the call.
     */
    public function run(): void
    {
        $this->call([
            DemoConsultantsSeeder::class,
            DemoClientSeeder::class,
        ]);
    }
}
