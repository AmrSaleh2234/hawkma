<?php

namespace Modules\Packages\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => [
                'sometimes', 'string', 'alpha_dash', 'max:50',
                Rule::unique('packages', 'slug')->ignore($this->route('package')),
            ],
            'name_ar' => ['sometimes', 'string', 'max:100'],
            'name_en' => ['sometimes', 'string', 'max:100'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'features' => ['sometimes', 'nullable', 'array'],
            'features.*.ar' => ['required', 'string'],
            'features.*.en' => ['required', 'string'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'billing_period_days' => ['sometimes', 'integer', 'min:1'],
            'consultations_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'documents_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
