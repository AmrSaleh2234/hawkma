<?php

namespace Modules\Bookings\Enums;

enum ReportStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Uploaded = 'uploaded';
}
