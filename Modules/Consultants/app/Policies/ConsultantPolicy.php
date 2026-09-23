<?php

namespace Modules\Consultants\Policies;

use Modules\Users\Models\User;

/**
 * A consultant is never allowed on /admin/consultants/* for another
 * consultant (plan §9.8): admins can do everything, a consultant only
 * reaches his own record.
 */
class ConsultantPolicy
{
    public function view(User $user, User $consultant): bool
    {
        return $user->isAdmin() || $user->id === $consultant->id;
    }

    public function update(User $user, User $consultant): bool
    {
        return $this->view($user, $consultant);
    }

    public function delete(User $user, User $consultant): bool
    {
        return $this->view($user, $consultant);
    }

    public function manageAvailability(User $user, User $consultant): bool
    {
        return $this->view($user, $consultant);
    }
}
