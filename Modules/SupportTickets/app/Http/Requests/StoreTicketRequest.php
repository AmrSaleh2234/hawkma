<?php

namespace Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SupportTickets\Enums\TicketCategory;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category' => ['required', Rule::enum(TicketCategory::class)], 'description' => ['required', 'string', 'max:5000'], 'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'], 'voice' => ['nullable', 'file', 'mimes:mp3,m4a,ogg,wav,webm', 'max:20480']];
    }
}
