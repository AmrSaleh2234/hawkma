<?php

namespace Modules\AccessControl\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('roles', 'name')->where('guard_name', 'admin')->ignore($role?->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'admin'),
            ],
        ];
    }
}
