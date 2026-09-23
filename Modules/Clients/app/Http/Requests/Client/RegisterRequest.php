<?php

namespace Modules\Clients\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:clients,email'],
            'phone' => ['required', 'string', 'regex:/^05\d{8}$/'],
            'company_name' => ['required', 'string', 'max:191'],
            'password' => ['required', 'confirmed', 'min:8'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
