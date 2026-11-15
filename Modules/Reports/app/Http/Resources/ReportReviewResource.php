<?php

namespace Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the review history of a report. `download_url` streams the
 * file version attached to this step (admin guard only).
 */
class ReportReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'note' => $this->note,
            'actor' => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ],
            'file' => $this->media === null ? null : [
                'name' => $this->media->file_name,
                'size' => $this->media->size,
                'size_human' => $this->media->human_readable_size,
                'mime_type' => $this->media->mime_type,
            ],
            'download_url' => $request->user('admin') !== null && $this->media_id !== null
                ? route('admin.reports.reviews.download', ['review' => $this->id])
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
