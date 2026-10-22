<?php

namespace Modules\Consultants\Services;

use Modules\Consultants\Models\TrainingSession;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Users\Models\User;

/**
 * The training pipeline between an approved join-request and a public,
 * bookable consultant: admins schedule one or more sessions with the
 * trainee, then promote him once coaching is done.
 */
class TrainingService
{
    public function schedule(User $trainee, array $data, User $actor): TrainingSession
    {
        $this->assertTrainee($trainee);

        return $trainee->trainingSessions()->create($data + ['scheduled_by' => $actor->id]);
    }

    public function deleteSession(User $trainee, TrainingSession $session): void
    {
        $this->assertTrainee($trainee);

        $session->delete();
    }

    /**
     * Promote: the consultant becomes publicly listed and bookable.
     */
    public function promote(User $trainee): User
    {
        $this->assertTrainee($trainee);

        $trainee->forceFill(['consultant_stage' => 'active'])->save();

        return $trainee;
    }

    private function assertTrainee(User $user): void
    {
        if (! $user->isTrainee()) {
            throw new BusinessException(ErrorCode::NotFound, __('consultants::training.not_trainee'), 404);
        }
    }
}
