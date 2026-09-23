<?php

namespace Modules\Packages\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Packages\Models\Package;

class PackagesSeeder extends Seeder
{
    /**
     * Seed the three subscription packages (plan §8.7). Idempotent via
     * updateOrCreate on the slug.
     */
    public function run(): void
    {
        $packages = [
            [
                'slug' => 'iron',
                'name_ar' => 'الباقة الحديدية',
                'name_en' => 'Iron Package',
                'description_ar' => 'للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.',
                'description_en' => 'For start-up establishments beginning their governance and compliance journey.',
                'features' => [
                    ['ar' => 'استشارتان شهرياً', 'en' => 'Two consultations monthly'],
                    ['ar' => 'مراجعة مستندين شهرياً', 'en' => 'Review two documents monthly'],
                    ['ar' => 'الدعم عبر البريد الإلكتروني', 'en' => 'Support via email'],
                    ['ar' => 'تقرير متابعة ربع سنوي', 'en' => 'Quarterly follow-up report'],
                ],
                'price' => 190000,
                'consultations_limit' => 2,
                'documents_limit' => 2,
                'is_featured' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => 'silver',
                'name_ar' => 'الباقة الفضية',
                'name_en' => 'Silver Package',
                'description_ar' => 'للمنشآت النامية التي تحتاج إلى دعم استشاري أعمق.',
                'description_en' => 'For growing establishments that need deeper consultancy support.',
                'features' => [
                    ['ar' => '5 استشارات شهرياً', 'en' => '5 consultations monthly'],
                    ['ar' => 'مراجعة حتى 8 مستندات شهرياً', 'en' => 'Review up to 8 documents monthly'],
                    ['ar' => 'الدعم عبر الهاتف والبريد الإلكتروني', 'en' => 'Support via phone and email'],
                    ['ar' => 'تقرير أداء شهري', 'en' => 'Monthly performance report'],
                    ['ar' => 'جلسة تدريبية ربع سنوية', 'en' => 'Quarterly training session'],
                ],
                'price' => 450000,
                'consultations_limit' => 5,
                'documents_limit' => 8,
                'is_featured' => false,
                'sort_order' => 2,
            ],
            [
                'slug' => 'gold',
                'name_ar' => 'الباقة الذهبية',
                'name_en' => 'Gold Package',
                'description_ar' => 'دعم شامل للمنشآت الطامحة إلى الريادة.',
                'description_en' => 'Comprehensive support for establishments aspiring to leadership.',
                'features' => [
                    ['ar' => 'استشارات غير محدودة', 'en' => 'Unlimited consultations'],
                    ['ar' => 'مراجعات مستندات غير محدودة', 'en' => 'Unlimited document reviews'],
                    ['ar' => 'مستشار مخصص لمنشأتك', 'en' => 'Dedicated advisor for your establishment'],
                    ['ar' => 'دعم على مدار الساعة طوال أيام الأسبوع', 'en' => '24/7 support'],
                    ['ar' => 'تقارير أداء أسبوعية', 'en' => 'Weekly performance reports'],
                    ['ar' => 'حضور اجتماعات مجلس الإدارة', 'en' => 'Attendance at board meetings'],
                ],
                'price' => 980000,
                'consultations_limit' => null,
                'documents_limit' => null,
                'is_featured' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $attributes) {
            $package = Package::withTrashed()->updateOrCreate(
                ['slug' => $attributes['slug']],
                $attributes,
            );

            if ($package->trashed()) {
                $package->restore();
            }
        }
    }
}
