<?php

namespace Modules\Consultants\Support;

/**
 * Day names for the AvailabilityResource (both languages are always present
 * in the response, so the app locale cannot be used).
 */
final class WeekDays
{
    public const EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public const AR = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    public static function name(int $dayOfWeek, string $locale = 'en'): string
    {
        return ($locale === 'ar' ? self::AR : self::EN)[$dayOfWeek];
    }
}
