<?php

return [
    'booking_confirmed' => [
        'subject' => 'تم تأكيد حجزك :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'تم تأكيد حجزك مع :consultant يوم :date الساعة :time (بتوقيت الرياض) ضمن باقة :package.',
        'location' => 'الموقع: :location',
        'join' => 'انضم إلى الاجتماع',
        'link_later' => 'سيتم إرسال رابط الاجتماع لاحقاً.',
        'amount' => 'المبلغ المدفوع: :amount',
    ],
    'new_booking' => [
        'subject' => 'حجز جديد :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'لديك حجز جديد من :company يوم :date الساعة :time (بتوقيت الرياض).',
        'join' => 'رابط الاجتماع',
    ],
    'booking_cancelled' => [
        'subject' => 'تم إلغاء الحجز :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => [
            'client' => 'تم إلغاء حجزك :reference المجدول يوم :date الساعة :time.',
            'consultant' => 'تم إلغاء الحجز :reference مع :company المجدول يوم :date الساعة :time.',
        ],
        'reason' => 'السبب: :reason',
    ],
    'cant_attend' => [
        'subject' => 'طلب تعذر حضور :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'قدّم المستشار :consultant طلب تعذر حضور للحجز :reference المجدول يوم :date الساعة :time.',
        'reason' => 'السبب: :reason',
    ],
    'booking_reassigned' => [
        'subject' => 'تم تغيير مستشار الحجز :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => [
            'client' => 'تم تعيين المستشار :new لحجزك :reference يوم :date الساعة :time بدلاً من :old.',
            'old_consultant' => 'تم نقل الحجز :reference المجدول يوم :date الساعة :time إلى المستشار :new.',
            'new_consultant' => 'تم تعيينك مستشاراً للحجز :reference مع :company يوم :date الساعة :time بدلاً من :old.',
        ],
    ],
];
