<?php

namespace Modules\AccessControl\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles', 'name')->where('guard_name', 'admin'),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'admin'),
            ],
        ];
    }
}
