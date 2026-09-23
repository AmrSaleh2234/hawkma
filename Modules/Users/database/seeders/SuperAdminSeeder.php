<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Models\Role;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'admin@gcmc.sa')],
            [
                'type' => UserType::Admin,
                'name' => 'Super Admin',
                'password' => env('SUPER_ADMIN_PASSWORD', 'Password@123'),
                'is_active' => true,
            ],
        );

        $user->assignRole(Role::ADMIN);
    }
}
