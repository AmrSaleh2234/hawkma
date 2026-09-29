<?php

namespace Modules\Bookings\Enums;

enum MeetingStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Created = 'created';
    case Failed = 'failed';
}
