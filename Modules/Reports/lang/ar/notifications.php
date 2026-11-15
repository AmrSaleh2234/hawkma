<?php

return [
    'report_ready' => [
        'subject' => 'تقريرك جاهز: :title',
        'greeting' => 'مرحباً :name،',
        'intro' => 'قام :consultant برفع التقرير «:title» الخاص بحجزك.',
        'view' => 'عرض التقرير في لوحة التحكم',
        'direct_download' => 'أو حمّله مباشرة عبر هذا الرابط (صالح لمدة 7 أيام):',
    ],
    'report_reviewed' => [
        'subject' => 'تحديث مراجعة التقرير: :title',
        'greeting' => 'مرحباً :name،',
        'note' => 'ملاحظة المراجع: :note',
        'view' => 'فتح التقرير',
        'approved' => [
            'line' => 'تمت الموافقة على تقريرك «:title» وتم إرساله إلى العميل.',
        ],
        'rejected' => [
            'line' => 'تم رفض تقريرك «:title».',
        ],
        'returned' => [
            'line' => 'تمت إعادة تقريرك «:title» للتعديل. الرجاء تحديثه ورفعه مرة أخرى.',
        ],
    ],
];
