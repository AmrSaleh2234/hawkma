<?php

namespace Modules\Consultants\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Consultants\Http\Requests\Concerns\ValidatesAvailability;

class StoreConsultantRequest extends FormRequest
{
    use ValidatesAvailability;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'title' => ['nullable', 'string', 'max:150'],
            'specialization' => ['nullable', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'confirmed'],
            'is_active' => ['boolean'],
            'availability' => ['nullable', 'array'],
            'availability.days' => ['required_with:availability', 'array', 'max:7'],
            'availability.days.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'availability.days.*.ranges' => ['present', 'array'],
            'availability.days.*.ranges.*.start_time' => ['required', 'date_format:H:i'],
            'availability.days.*.ranges.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function () {
                if (is_array($this->input('availability'))) {
                    $this->validateAvailabilityRanges($this->input('availability.days', []), 'availability.days');
                }
            },
        ];
    }
}
