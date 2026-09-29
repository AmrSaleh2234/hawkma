<?php

namespace Modules\Consultants\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Consultants\Models\JoinRequest;

class UpdateJoinRequestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(JoinRequest::STATUSES)],
        ];
    }
}
