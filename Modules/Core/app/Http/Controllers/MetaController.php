<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;

class MetaController extends ApiController
{
    /**
     * PUB-08 GET /api/v1/public/meta
     *
     * Enum values and labels for the frontend, plus the public booking and
     * payment configuration.
     */
    public function __invoke(): JsonResponse
    {
        return $this->success([
            // Placeholders until Phase 9 introduces the booking enums.
            'booking_statuses' => [],
            'report_statuses' => [],
            'payment_statuses' => [],
            'days_of_week' => $this->daysOfWeek(),
            'booking' => [
                'slot_minutes' => (int) config('bookings.slot_minutes'),
                'duration_minutes' => (int) config('bookings.duration_minutes'),
                'max_advance_days' => (int) config('bookings.max_advance_days'),
                'min_notice_minutes' => (int) config('bookings.min_notice_minutes'),
                'client_cancel_hours' => (int) config('bookings.client_cancel_hours'),
            ],
            'payment_gateway' => [
                'driver' => config('payments.driver'),
                'publishable_key' => config('payments.moyasar.publishable_key'),
            ],
        ]);
    }

    /**
     * @return array<int, array{value: int, name: string}>
     */
    protected function daysOfWeek(): array
    {
        $names = [
            'ar' => ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
            'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ];

        $locale = app()->getLocale();

        return collect(range(0, 6))
            ->map(fn (int $day) => ['value' => $day, 'name' => $names[$locale][$day] ?? $names['en'][$day]])
            ->all();
    }
}
