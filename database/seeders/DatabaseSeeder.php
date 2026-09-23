<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Users\Database\Seeders\SuperAdminSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
