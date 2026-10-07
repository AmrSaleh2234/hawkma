<?php

namespace Modules\JoinRequests\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cv = $this->getFirstMedia('cv');
        $cvUrl = $cv !== null
            ? url('/api/v1/admin/join-requests/'.$this->id.'/cv')
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'specialization' => $this->specialization,
            'bio' => $this->bio,
            'linkedin_url' => $this->linkedin_url,
            'cv_url' => $cvUrl,
            'cv' => $cv !== null ? [
                'name' => $cv->file_name,
                'mime_type' => $cv->mime_type,
                'size' => $cv->size,
                'download_url' => $cvUrl,
            ] : null,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'user_id' => $this->user_id,
            'reviewed_by' => $this->reviewed_by,
            'reviewer' => $this->when($this->relationLoaded('reviewer'), fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
