<?php

namespace Modules\Payments\Enums;

enum PaymentRecordStatus: string
{
    case Initiated = 'initiated';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
