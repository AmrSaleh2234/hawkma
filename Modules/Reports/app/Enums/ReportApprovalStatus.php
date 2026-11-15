<?php

namespace Modules\Reports\Enums;

/**
 * The review lifecycle of a report: each upload (re)submits it for review;
 * an admin then approves, rejects or returns it to the consultant. Only an
 * approved report is visible to the client.
 */
enum ReportApprovalStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
}
