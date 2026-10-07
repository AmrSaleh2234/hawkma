<?php

namespace Modules\ActivityLogs\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ActivityLogs\Enums\ActivityEvent;
use Modules\ActivityLogs\Models\ActivityLog;

class ActivityLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * date_from / date_to accept `Y-m-d` or a full datetime so the list can
     * be narrowed to a specific day or an exact time range.
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'module' => ['nullable', 'string', 'max:50'],
            'event' => ['nullable', 'string', Rule::in(ActivityEvent::values())],
            'log_name' => ['nullable', 'string', Rule::in([ActivityLog::NAME_SYSTEM, ActivityLog::NAME_API])],
            'search' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', Rule::in(['created_at', '-created_at'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
