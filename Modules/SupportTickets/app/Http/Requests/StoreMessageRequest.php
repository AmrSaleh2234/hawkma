<?php

namespace Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['body' => ['nullable', 'required_without_all:image,voice', 'string', 'max:5000'], 'image' => ['nullable', 'required_without_all:body,voice', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'], 'voice' => ['nullable', 'required_without_all:body,image', 'file', 'mimes:mp3,m4a,ogg,wav,webm', 'max:20480'], 'is_internal' => ['sometimes', 'boolean']];
    }
}
