<?php

namespace Modules\Payments\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
