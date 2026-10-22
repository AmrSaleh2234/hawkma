<?php

namespace Modules\JoinRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJoinRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email'), Rule::unique('join_requests', 'email')->where('status', 'pending')],
            'phone' => ['required', 'string', 'max:30'],
            'specialization' => ['required', 'string', 'max:191'],
            'bio' => ['nullable', 'string', 'max:100'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'licenses' => ['nullable', 'array', 'max:10'],
            'licenses.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ];
    }
}
