<?php

namespace Modules\Consultants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJoinRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'qualification' => ['required', 'string', 'max:191'],
            'experience' => ['nullable', 'string', 'max:191'],
            'service_fields' => ['nullable', 'string', 'max:191'],
            'licenses' => ['nullable', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:191'],
            'country_city' => ['nullable', 'string', 'max:191'],
            'social_accounts' => ['nullable', 'string', 'max:191'],
            'linkedin' => ['nullable', 'string', 'max:191'],
        ];
    }
}
