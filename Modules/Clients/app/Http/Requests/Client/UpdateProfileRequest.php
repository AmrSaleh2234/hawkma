<?php

namespace Modules\Clients\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', Rule::unique('clients', 'email')->ignore($this->user('client'))],
            'phone' => ['required', 'string', 'regex:/^05\d{8}$/'],
            'company_name' => ['required', 'string', 'max:191'],
        ];
    }
}
