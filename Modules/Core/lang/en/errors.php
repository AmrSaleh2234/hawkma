<?php

return [
    'VALIDATION_ERROR' => 'The given data was invalid.',
    'UNAUTHENTICATED' => 'Unauthenticated.',
    'FORBIDDEN' => 'You do not have permission to perform this action.',
    'ACCOUNT_DISABLED' => 'This account is disabled.',
    'NOT_FOUND' => 'The requested resource was not found.',
    'METHOD_NOT_ALLOWED' => 'This method is not allowed for this endpoint.',
    'TOO_MANY_REQUESTS' => 'Too many requests. Please slow down.',
    'SERVER_ERROR' => 'An unexpected error occurred. Please try again later.',

    'INVALID_CREDENTIALS' => 'These credentials do not match our records.',

    'ROLE_PROTECTED' => 'This role is protected and cannot be modified or deleted.',
    'ROLE_HAS_USERS' => 'This role still has users and cannot be deleted.',
    'CANNOT_DELETE_SELF' => 'You cannot delete your own account.',
    'LAST_ADMIN' => 'You cannot remove or delete the last active admin.',

    'CONSULTANT_INACTIVE' => 'The consultant is not active.',
    'CONSULTANT_HAS_FUTURE_BOOKINGS' => 'The consultant has future bookings and cannot be deleted.',
    'CLIENT_HAS_FUTURE_BOOKINGS' => 'The client has future bookings and cannot be deleted.',
    'AVAILABILITY_OVERLAP' => 'The availability time ranges overlap.',
    'SLOT_NOT_AVAILABLE' => 'This time slot is no longer available.',

    'PACKAGE_INACTIVE' => 'This package is not active.',
    'PACKAGE_HAS_SUBSCRIPTIONS' => 'This package has subscriptions and cannot be deleted.',

    'LOCATION_NOT_OWNED' => 'This location does not belong to you.',

    'PAYMENT_METHOD_REQUIRED' => 'A payment method is required.',
    'PAYMENT_METHOD_NOT_OWNED' => 'This payment method does not belong to you.',
    'PAYMENT_FAILED' => 'The payment failed. Please try another card.',
    'PAYMENT_ALREADY_PROCESSED' => 'This payment has already been processed.',

    'BOOKING_INVALID_STATUS' => 'The booking status does not allow this action.',
    'BOOKING_NOT_STARTED' => 'The booking has not started yet.',
    'BOOKING_CANCEL_WINDOW_PASSED' => 'The cancellation window has passed.',

    'REPORT_NOT_ALLOWED' => 'A report can only be uploaded for a completed booking.',

    'MEETING_CREATION_FAILED' => 'Could not create the online meeting.',
];
