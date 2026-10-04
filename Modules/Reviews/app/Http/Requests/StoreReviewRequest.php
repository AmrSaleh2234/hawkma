<?php

namespace Modules\Reviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:191'], 'rating' => ['required', 'integer', 'min:1', 'max:5'], 'title' => ['nullable', 'string', 'max:191'], 'comment' => ['required', 'string', 'max:2000']];
    }
}
