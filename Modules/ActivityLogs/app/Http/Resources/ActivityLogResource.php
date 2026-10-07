<?php

namespace Modules\ActivityLogs\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'module' => $this->module,
            'event' => $this->event,
            'description' => $this->description,
            'subject' => $this->subject_type === null ? null : [
                'type' => class_basename($this->subject_type),
                'id' => $this->subject_id,
                'name' => $this->subject?->name ?? $this->subject?->title ?? null,
            ],
            'causer' => $this->causer_type === null ? null : [
                'type' => class_basename($this->causer_type),
                'id' => $this->causer_id,
                'name' => $this->causer?->name ?? null,
            ],
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
