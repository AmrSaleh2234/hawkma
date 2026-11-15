<?php

namespace Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan §10.1. `download_url` is guard-specific: admins get the admin
 * download route, clients get theirs.
 */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->currentMedia();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'approval_status' => $this->approval_status->value,
            'review_note' => $this->review_note,
            'reviewed_by' => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'booking' => $this->booking === null ? null : [
                'id' => $this->booking->id,
                'reference' => $this->booking->reference,
                'date' => $this->booking->starts_at->format('Y-m-d'),
                'time' => $this->booking->starts_at->format('H:i'),
            ],
            'consultant' => $this->consultant === null ? null : [
                'id' => $this->consultant->id,
                'name' => $this->consultant->name,
            ],
            'client' => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'company_name' => $this->client->company_name,
            ],
            'file' => $media === null ? null : [
                'name' => $media->file_name,
                'size' => $media->size,
                'size_human' => $media->human_readable_size,
                'mime_type' => $media->mime_type,
            ],
            'download_url' => $this->downloadUrl($request),
            'client_notified_at' => $this->client_notified_at?->toIso8601String(),
            'first_downloaded_at' => $this->first_downloaded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function downloadUrl(Request $request): ?string
    {
        if ($request->user('admin') !== null) {
            return route('admin.reports.download', $this->id);
        }

        if ($request->user('client') !== null) {
            return route('client.reports.download', $this->id);
        }

        return null;
    }
}
