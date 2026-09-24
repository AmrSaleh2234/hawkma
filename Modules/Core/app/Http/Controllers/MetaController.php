<?php

namespace Modules\Core\Http\Controllers;

use BackedEnum;
use Illuminate\Http\JsonResponse;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\ReportStatus;

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
            'booking_statuses' => $this->enumOptions(BookingStatus::cases(), 'bookings::labels.status'),
            'report_statuses' => $this->enumOptions(ReportStatus::cases(), 'bookings::labels.report_status'),
            'payment_statuses' => $this->enumOptions(PaymentStatus::cases(), 'bookings::labels.payment_status'),
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
     * @param  array<int, BackedEnum>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    protected function enumOptions(array $cases, string $langKey): array
    {
        return collect($cases)
            ->map(fn (BackedEnum $case) => [
                'value' => $case->value,
                'label' => __("{$langKey}.{$case->value}"),
            ])
            ->all();
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
