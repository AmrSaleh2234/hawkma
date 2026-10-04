<?php

namespace Modules\JoinRequests\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\JoinRequests\Enums\JoinRequestStatus;
use Modules\JoinRequests\Models\JoinRequest;
use Modules\JoinRequests\Notifications\JoinRequestRejectedNotification;
use Modules\JoinRequests\Notifications\JoinRequestSubmittedNotification;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;
use Modules\Users\Services\UserService;

class JoinRequestService
{
    public function __construct(private UserService $users) {}

    public function submit(array $data, UploadedFile $cv): JoinRequest
    {
        $request = JoinRequest::query()->create($data + ['status' => JoinRequestStatus::Pending]);
        $request->addMedia($cv)->toMediaCollection('cv');
        Notification::send(User::query()->staff()->active()->get(), new JoinRequestSubmittedNotification($request));

        return $request->load(['media']);
    }

    public function approve(JoinRequest $request, User $reviewer): JoinRequest
    {
        return DB::transaction(function () use ($request, $reviewer): JoinRequest {
            $request = JoinRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);
            if (User::query()->where('email', $request->email)->exists()) {
                throw ValidationException::withMessages(['email' => [__('joinrequests::messages.email_exists')]]);
            }
            $user = $this->users->create(['type' => UserType::Consultant->value, 'name' => $request->name, 'email' => $request->email, 'phone' => $request->phone, 'specialization' => $request->specialization, 'bio' => $request->bio, 'is_active' => true]);
            $request->forceFill(['status' => JoinRequestStatus::Approved, 'user_id' => $user->id, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => null])->save();

            return $request->load(['user', 'reviewer', 'media']);
        });
    }

    public function reject(JoinRequest $request, User $reviewer, string $reason): JoinRequest
    {
        $request = DB::transaction(function () use ($request, $reviewer, $reason): JoinRequest {
            $request = JoinRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertPending($request);
            $request->forceFill(['status' => JoinRequestStatus::Rejected, 'rejection_reason' => $reason, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();

            return $request->load(['reviewer', 'media']);
        });
        Notification::route('mail', $request->email)->notify(new JoinRequestRejectedNotification($request));

        return $request;
    }

    private function assertPending(JoinRequest $request): void
    {
        if ($request->status !== JoinRequestStatus::Pending) {
            throw ValidationException::withMessages(['status' => [__('joinrequests::messages.already_reviewed')]]);
        }
    }
}
