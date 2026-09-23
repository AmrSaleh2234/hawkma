<?php

namespace Modules\Consultants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailableDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}
