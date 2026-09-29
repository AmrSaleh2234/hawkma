<?php

namespace Modules\Reports\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:20480'],
            'notify_client' => ['boolean'],
        ];
    }
}
