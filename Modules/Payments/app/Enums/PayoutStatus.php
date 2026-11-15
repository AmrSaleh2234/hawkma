<?php

namespace Modules\Payments\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Released = 'released';
    case Failed = 'failed';
}
