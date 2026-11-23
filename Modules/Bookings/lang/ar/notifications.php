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
    'transfer_requested' => [
        'subject' => 'طلب تحويل للحجز :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'طلب :from تحويل الحجز :reference (:company — يوم :date الساعة :time) إلى :to. السبب: :reason',
    ],
    'transfer_approved' => [
        'subject' => 'تم تحويل الحجز :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'تم تحويل الحجز :reference (:company — يوم :date الساعة :time) من :from إلى :to.',
    ],
    'transfer_rejected' => [
        'subject' => 'تم رفض طلب تحويل الحجز :reference',
        'greeting' => 'مرحباً :name،',
        'intro' => 'تم رفض طلبك بتحويل الحجز :reference إلى :to. ملاحظة: :note',
    ],
];
