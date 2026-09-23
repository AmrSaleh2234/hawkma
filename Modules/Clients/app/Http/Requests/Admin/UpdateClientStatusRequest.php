<?php

namespace Modules\Clients\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
