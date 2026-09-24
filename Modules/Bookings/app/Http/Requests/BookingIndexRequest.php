<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Bookings\Enums\BookingStatus;
use Modules\Bookings\Enums\PaymentStatus;
use Modules\Bookings\Enums\ReportStatus;

/**
 * The shared booking list filters (BKG-01, CON-15).
 */
class BookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'report_status' => ['nullable', Rule::enum(ReportStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:191'],
            'sort' => ['nullable', 'string', Rule::in(['starts_at', '-starts_at', 'created_at', '-created_at', 'amount', '-amount'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
