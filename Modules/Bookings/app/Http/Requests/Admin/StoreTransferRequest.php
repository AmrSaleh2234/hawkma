<?php

namespace Modules\Bookings\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'to_consultant_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
