<?php

namespace Modules\Reports\Policies;

use Modules\Reports\Models\Report;
use Modules\Users\Models\User;

/**
 * Data visibility (§9.8): an admin may act on any report; a consultant
 * only on his own. Another consultant's record is a 403.
 */
class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        return $user->isAdmin() || $report->consultant_id === $user->id;
    }

    public function download(User $user, Report $report): bool
    {
        return $user->isAdmin() || $report->consultant_id === $user->id;
    }

    public function delete(User $user, Report $report): bool
    {
        return $user->isAdmin() || $report->consultant_id === $user->id;
    }

    public function notifyClient(User $user, Report $report): bool
    {
        return $user->isAdmin() || $report->consultant_id === $user->id;
    }
}
