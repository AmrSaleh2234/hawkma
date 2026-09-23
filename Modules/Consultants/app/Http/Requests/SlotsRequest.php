<?php

namespace Modules\Consultants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
