<?php

namespace Modules\Bookings\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CompleteBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
