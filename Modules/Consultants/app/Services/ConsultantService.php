<?php

namespace Modules\Consultants\Services;

use Illuminate\Http\UploadedFile;
use Modules\AccessControl\Models\Role;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;
use Modules\Users\Services\AvatarService;
use Modules\Users\Services\UserService;

class ConsultantService
{
    public function __construct(
        protected UserService $users,
        protected AvatarService $avatars,
        protected AvailabilityService $availability,
    ) {}

    /**
     * CON-02: create a consultant. The type and the `consultant` role are set
     * automatically (the request cannot choose roles here). When no password
     * is given, UserService sends the StaffAccountCreatedNotification.
     */
    public function create(array $data, ?UploadedFile $photo = null): User
    {
        $availability = $data['availability']['days'] ?? null;
        unset($data['availability'], $data['photo']);

        $consultant = $this->users->create(array_merge($data, [
            'type' => UserType::Consultant,
            'roles' => [Role::CONSULTANT],
        ]));

        if ($photo) {
            $this->avatars->update($consultant, $photo);
        }

        if (is_array($availability)) {
            $this->availability->replaceWeek($consultant, $availability);
        }

        return $consultant;
    }

    /**
     * CON-04: update a consultant (all fields optional).
     */
    public function update(User $consultant, array $data): User
    {
        unset($data['photo'], $data['availability']);

        return $this->users->update($consultant, $data);
    }

    /**
     * CON-05: soft delete; blocked by future pending bookings (UserService,
     * armed in Phase 9).
     */
    public function delete(User $consultant, User $actor): void
    {
        $this->users->delete($consultant, $actor);
    }

    /**
     * CON-06: activate/deactivate. Deactivating deletes the tokens; an
     * inactive consultant disappears from the public list and has no slots.
     */
    public function setActive(User $consultant, bool $isActive, User $actor): User
    {
        return $this->users->updateStatus($consultant, $isActive, $actor);
    }

    /**
     * CON-07: replace the photo (the `avatar` media collection).
     */
    public function updatePhoto(User $consultant, UploadedFile $photo): User
    {
        $this->avatars->update($consultant, $photo);

        return $consultant;
    }
}
