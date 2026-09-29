<?php

namespace Modules\Bookings\Enums;

enum RefundStatus: string
{
    case None = 'none';
    case Requested = 'requested';
    case Refunded = 'refunded';
}
