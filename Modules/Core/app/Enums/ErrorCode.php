<?php

namespace Modules\Core\Enums;

enum ErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';

    case InvalidCredentials = 'INVALID_CREDENTIALS';

    case RoleProtected = 'ROLE_PROTECTED';
    case RoleHasUsers = 'ROLE_HAS_USERS';
    case CannotDeleteSelf = 'CANNOT_DELETE_SELF';
    case LastAdmin = 'LAST_ADMIN';

    case ConsultantInactive = 'CONSULTANT_INACTIVE';
    case ConsultantHasFutureBookings = 'CONSULTANT_HAS_FUTURE_BOOKINGS';
    case ClientHasFutureBookings = 'CLIENT_HAS_FUTURE_BOOKINGS';
    case AvailabilityOverlap = 'AVAILABILITY_OVERLAP';
    case SlotNotAvailable = 'SLOT_NOT_AVAILABLE';

    case PackageInactive = 'PACKAGE_INACTIVE';
    case PackageHasSubscriptions = 'PACKAGE_HAS_SUBSCRIPTIONS';

    case LocationNotOwned = 'LOCATION_NOT_OWNED';

    case PaymentMethodRequired = 'PAYMENT_METHOD_REQUIRED';
    case PaymentMethodNotOwned = 'PAYMENT_METHOD_NOT_OWNED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case PaymentAlreadyProcessed = 'PAYMENT_ALREADY_PROCESSED';

    case BookingInvalidStatus = 'BOOKING_INVALID_STATUS';
    case BookingNotStarted = 'BOOKING_NOT_STARTED';
    case BookingCancelWindowPassed = 'BOOKING_CANCEL_WINDOW_PASSED';

    case ReportNotAllowed = 'REPORT_NOT_ALLOWED';

    case MeetingCreationFailed = 'MEETING_CREATION_FAILED';
}
