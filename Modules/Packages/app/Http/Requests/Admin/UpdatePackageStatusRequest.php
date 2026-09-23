<?php

namespace Modules\Packages\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageStatusRequest extends FormRequest
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
