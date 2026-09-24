<?php

namespace Modules\Bookings\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * BKG-06: from/to are required and the range is capped at 62 days.
 */
class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = CarbonImmutable::parse($this->query('from'));
            $to = CarbonImmutable::parse($this->query('to'));

            if ($from->diffInDays($to) > 62) {
                $validator->errors()->add('to', __('bookings::validation.calendar_range'));
            }
        });
    }
}
