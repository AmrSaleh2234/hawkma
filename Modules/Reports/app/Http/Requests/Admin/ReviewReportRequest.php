<?php

namespace Modules\Reports\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional reviewer note sent with approve/reject/return decisions.
 */
class ReviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
