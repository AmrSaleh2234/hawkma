<?php

namespace Modules\Bookings\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
