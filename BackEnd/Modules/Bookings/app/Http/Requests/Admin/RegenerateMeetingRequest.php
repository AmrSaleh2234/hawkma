<?php

namespace Modules\Bookings\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notify_client' => ['boolean'],
        ];
    }
}
