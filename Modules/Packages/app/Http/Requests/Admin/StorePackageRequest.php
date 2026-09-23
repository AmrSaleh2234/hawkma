<?php

namespace Modules\Packages\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'alpha_dash', 'max:50', 'unique:packages,slug'],
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['required', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'features' => ['nullable', 'array'],
            'features.*.ar' => ['required', 'string'],
            'features.*.en' => ['required', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'billing_period_days' => ['sometimes', 'integer', 'min:1'],
            'consultations_limit' => ['nullable', 'integer', 'min:1'],
            'documents_limit' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
