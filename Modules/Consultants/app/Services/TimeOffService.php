<?php

namespace Modules\Consultants\Services;

use Modules\Consultants\Models\ConsultantTimeOff;
use Modules\Users\Models\User;

class TimeOffService
{
    /**
     * CON-11: add a time off (a whole day when start_time/end_time are null).
     */
    public function create(User $consultant, array $data): ConsultantTimeOff
    {
        return $consultant->timeOffs()->create($data);
    }

    /**
     * CON-12 / MY-05: delete a time off.
     */
    public function delete(ConsultantTimeOff $timeOff): void
    {
        $timeOff->delete();
    }
}
