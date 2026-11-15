<?php

namespace Modules\Bookings\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class QuoteBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A quote needs a package or a consultant: package → package
            // quote; consultant only → direct-booking quote (his price).
            'package_id' => ['required_without:consultant_id', 'nullable', 'integer', 'exists:packages,id'],
            'consultant_id' => ['required_without:package_id', 'nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
