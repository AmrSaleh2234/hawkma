<?php

return [
    'VALIDATION_ERROR' => 'البيانات المدخلة غير صحيحة.',
    'UNAUTHENTICATED' => 'غير مصرح. الرجاء تسجيل الدخول.',
    'FORBIDDEN' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    'ACCOUNT_DISABLED' => 'هذا الحساب معطّل.',
    'NOT_FOUND' => 'المورد المطلوب غير موجود.',
    'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير مسموح بها لهذا المسار.',
    'TOO_MANY_REQUESTS' => 'طلبات كثيرة جداً. الرجاء المحاولة لاحقاً.',
    'SERVER_ERROR' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',

    'INVALID_CREDENTIALS' => 'بيانات الدخول غير صحيحة.',

    'ROLE_PROTECTED' => 'هذا الدور محمي ولا يمكن تعديله أو حذفه.',
    'ROLE_HAS_USERS' => 'هذا الدور مرتبط بمستخدمين ولا يمكن حذفه.',
    'CANNOT_DELETE_SELF' => 'لا يمكنك حذف حسابك الخاص.',
    'LAST_ADMIN' => 'لا يمكن حذف أو إزالة صلاحية آخر مدير نشط.',

    'CONSULTANT_INACTIVE' => 'المستشار غير نشط.',
    'CONSULTANT_HAS_FUTURE_BOOKINGS' => 'المستشار لديه حجوزات مستقبلية ولا يمكن حذفه.',
    'CLIENT_HAS_FUTURE_BOOKINGS' => 'العميل لديه حجوزات مستقبلية ولا يمكن حذفه.',
    'AVAILABILITY_OVERLAP' => 'فترات التوفر متداخلة.',
    'SLOT_NOT_AVAILABLE' => 'هذا الموعد لم يعد متاحاً.',

    'PACKAGE_INACTIVE' => 'هذه الباقة غير نشطة.',
    'PACKAGE_HAS_SUBSCRIPTIONS' => 'هذه الباقة مرتبطة باشتراكات ولا يمكن حذفها.',
    'SUBSCRIPTION_EXHAUSTED' => 'انتهى رصيد الاستشارات في هذا الاشتراك.',
    'SUBSCRIPTION_INACTIVE' => 'هذا الاشتراك لم يعد نشطاً.',

    'LOCATION_NOT_OWNED' => 'هذا الموقع لا يتبع لك.',

    'PAYMENT_METHOD_REQUIRED' => 'طريقة الدفع مطلوبة.',
    'PAYMENT_METHOD_NOT_OWNED' => 'طريقة الدفع هذه لا تتبع لك.',
    'PAYMENT_FAILED' => 'فشلت عملية الدفع. الرجاء تجربة بطاقة أخرى.',
    'PAYMENT_ALREADY_PROCESSED' => 'تمت معالجة هذه الدفعة مسبقاً.',
    'PAYMENT_PENDING_CONFIRMATION' => 'جارٍ تأكيد عملية الدفع. الرجاء التحقق من الحالة بعد قليل.',

    'BOOKING_INVALID_STATUS' => 'حالة الحجز لا تسمح بهذا الإجراء.',
    'BOOKING_NOT_STARTED' => 'لم يبدأ الحجز بعد.',
    'BOOKING_CANCEL_WINDOW_PASSED' => 'انتهت مهلة الإلغاء.',

    'REPORT_NOT_ALLOWED' => 'يمكن رفع التقرير فقط لحجز مكتمل.',

    'MEETING_CREATION_FAILED' => 'تعذر إنشاء الاجتماع عبر الإنترنت.',
];
