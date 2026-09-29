<?php

namespace Modules\Bookings\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case NotRequired = 'not_required';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
