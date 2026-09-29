<?php

namespace Modules\Bookings\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReassignBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consultant_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
