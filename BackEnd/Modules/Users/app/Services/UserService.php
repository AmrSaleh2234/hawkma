<?php

namespace Modules\Users\Services;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\AccessControl\Models\Role;
use Modules\Bookings\Models\Booking;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Users\Enums\UserType;
use Modules\Users\Models\User;
use Modules\Users\Notifications\StaffAccountCreatedNotification;

class UserService
{
    /**
     * Create a user (USR-02).
     *
     * When no password is given, a random one is generated and a
     * "set your password" email is sent (A13).
     */
    public function create(array $data): User
    {
        $sendWelcome = empty($data['password']);
        $data['password'] ??= Str::password(16);
        $data['type'] ??= UserType::Admin->value;

        $roles = $data['roles'] ?? [];
        unset($data['roles'], $data['avatar']);

        $user = User::create($data);

        if ($user->isConsultant() && ! in_array(Role::CONSULTANT, $roles, true)) {
            $roles[] = Role::CONSULTANT;
        }

        $user->syncRoles(array_unique($roles));

        if ($sendWelcome) {
            $this->sendSetPasswordNotification($user);
        }

        return $user;
    }

    /**
     * Update a user (USR-04). Does not change roles (use syncRoles).
     */
    public function update(User $user, array $data): User
    {
        unset($data['roles'], $data['avatar']);

        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();

        return $user;
    }

    /**
     * Delete a user (USR-05): soft delete + delete tokens.
     */
    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new BusinessException(ErrorCode::CannotDeleteSelf);
        }

        $this->assertNotLastActiveAdmin($user);
        $this->assertConsultantHasNoFutureBookings($user);

        $user->tokens()->delete();
        $user->delete();
    }

    /**
     * Sync the roles of a user (USR-06). The consultant role is always kept
     * for consultants (9.10).
     *
     * @param  array<int, string>  $roles
     */
    public function syncRoles(User $user, array $roles): User
    {
        if ($user->isConsultant() && ! in_array(Role::CONSULTANT, $roles, true)) {
            $roles[] = Role::CONSULTANT;
        }

        if ($user->hasRole(Role::ADMIN) && ! in_array(Role::ADMIN, $roles, true)) {
            $this->assertNotLastActiveAdmin($user);
        }

        $user->syncRoles(array_unique($roles));

        return $user->refresh();
    }

    /**
     * Activate or deactivate a user (USR-07). Deactivating deletes his tokens.
     */
    public function updateStatus(User $user, bool $isActive, User $actor): User
    {
        if (! $isActive) {
            if ($user->is($actor)) {
                throw new BusinessException(
                    ErrorCode::CannotDeleteSelf,
                    __('core::messages.cannot_deactivate_self'),
                );
            }

            $this->assertNotLastActiveAdmin($user);
        }

        $user->is_active = $isActive;
        $user->save();

        if (! $isActive) {
            $user->tokens()->delete();
        }

        return $user;
    }

    /**
     * The last active user with the admin role cannot be deleted, deactivated,
     * or lose the admin role (9.10).
     */
    protected function assertNotLastActiveAdmin(User $user): void
    {
        if (! $user->hasRole(Role::ADMIN) || ! $user->is_active) {
            return;
        }

        $anotherActiveAdminExists = User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->role(Role::ADMIN)
            ->exists();

        if (! $anotherActiveAdminExists) {
            throw new BusinessException(ErrorCode::LastAdmin);
        }
    }

    protected function assertConsultantHasNoFutureBookings(User $user): void
    {
        if (! $user->isConsultant()) {
            return;
        }

        // The Booking model is introduced in Phase 9; this check activates then.
        if (! class_exists(Booking::class)) {
            return;
        }

        $hasFuturePendingBookings = $user->consultantBookings()
            ->where('status', 'pending')
            ->where('starts_at', '>', now())
            ->exists();

        if ($hasFuturePendingBookings) {
            throw new BusinessException(ErrorCode::ConsultantHasFutureBookings, status: 409);
        }
    }

    protected function sendSetPasswordNotification(User $user): void
    {
        $token = Password::broker('users')->createToken($user);

        $url = rtrim((string) config('app.admin_frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email);

        $user->notify(new StaffAccountCreatedNotification($user->email, $url));
    }
}
