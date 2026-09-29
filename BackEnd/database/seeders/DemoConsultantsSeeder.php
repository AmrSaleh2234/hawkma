<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Models\Role;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;

class DemoConsultantsSeeder extends Seeder
{
    /**
     * The six consultants from the design screenshots (plan Phase 11), each
     * with the consultant role and availability Sunday–Thursday 09:00–17:00.
     * Idempotent via firstOrCreate on the email.
     */
    public function run(): void
    {
        $consultants = [
            ['name' => 'أ. أحمد العتيبي', 'title' => 'مستشار حوكمة', 'specialization' => 'الحوكمة المؤسسية', 'email' => 'ahmad.alotaibi@gcmc.sa'],
            ['name' => 'د. سارة الدوسري', 'title' => 'مستشار امتثال', 'specialization' => 'الامتثال التنظيمي', 'email' => 'sara.aldosari@gcmc.sa'],
            ['name' => 'م. محمد الشهري', 'title' => 'مستشار إداري', 'specialization' => 'تحسين الأداء', 'email' => 'mohammed.alshehri@gcmc.sa'],
            ['name' => 'أ. أروى العنزي', 'title' => 'مستشار موارد بشرية', 'specialization' => 'استقطاب المواهب', 'email' => 'arwa.alanazi@gcmc.sa'],
            ['name' => 'د. فهد القحطاني', 'title' => 'مستشار مالي', 'specialization' => 'إدارة المخاطر', 'email' => 'fahad.alqahtani@gcmc.sa'],
            ['name' => 'أ. نورة السبيعي', 'title' => 'مستشار جودة', 'specialization' => 'جودة العمليات', 'email' => 'noura.alsubaie@gcmc.sa'],
        ];

        foreach ($consultants as $attributes) {
            $user = User::firstOrCreate(
                ['email' => $attributes['email']],
                $attributes + [
                    'type' => UserType::Consultant,
                    'password' => 'Password@123',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            $user->assignRole(Role::CONSULTANT);

            foreach (range(0, 4) as $day) {
                $user->availabilities()->firstOrCreate(
                    ['day_of_week' => $day],
                    ['start_time' => '09:00', 'end_time' => '17:00'],
                );
            }
        }
    }
}
