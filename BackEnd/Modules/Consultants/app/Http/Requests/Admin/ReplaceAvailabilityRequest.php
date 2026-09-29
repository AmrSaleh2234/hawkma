<?php

namespace Modules\Consultants\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Consultants\Http\Requests\Concerns\ValidatesAvailability;

class ReplaceAvailabilityRequest extends FormRequest
{
    use ValidatesAvailability;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.ranges' => ['present', 'array'],
            'days.*.ranges.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            fn () => $this->validateAvailabilityRanges($this->input('days', [])),
        ];
    }
}
