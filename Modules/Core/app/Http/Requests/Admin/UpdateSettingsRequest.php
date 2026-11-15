<?php

namespace Modules\Core\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Support\Settings;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            Settings::PLATFORM_FEE_PERCENT => ['sometimes', 'numeric', 'min:0', 'max:100'],
            Settings::PAYOUT_AUTO_TRANSFER => ['sometimes', 'boolean'],
        ];
    }
}
