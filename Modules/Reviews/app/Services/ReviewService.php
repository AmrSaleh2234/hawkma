<?php

namespace Modules\Reviews\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Clients\Models\Client;
use Modules\Reviews\Enums\ReviewStatus;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Notifications\ReviewSubmittedNotification;
use Modules\Users\Models\User;

class ReviewService
{
    public function submit(Client $client, array $data): Review
    {
        $review = $client->reviews()->create($data + ['status' => ReviewStatus::Pending]);
        Notification::send(User::query()->staff()->active()->get(), new ReviewSubmittedNotification($review));

        return $review;
    }

    public function approve(Review $review, User $reviewer): Review
    {
        return DB::transaction(function () use ($review, $reviewer): Review {
            $review = Review::query()->lockForUpdate()->findOrFail($review->id);
            $this->assertPending($review);
            $review->forceFill(['status' => ReviewStatus::Approved, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'published_at' => now(), 'rejection_reason' => null])->save();

            return $review->load(['client', 'reviewer']);
        });
    }

    public function reject(Review $review, User $reviewer, string $reason): Review
    {
        return DB::transaction(function () use ($review, $reviewer, $reason): Review {
            $review = Review::query()->lockForUpdate()->findOrFail($review->id);
            $this->assertPending($review);
            $review->forceFill(['status' => ReviewStatus::Rejected, 'rejection_reason' => $reason, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'published_at' => null])->save();

            return $review->load(['client', 'reviewer']);
        });
    }

    private function assertPending(Review $review): void
    {
        if ($review->status !== ReviewStatus::Pending) {
            throw ValidationException::withMessages(['status' => [__('reviews::messages.already_reviewed')]]);
        }
    }
}
