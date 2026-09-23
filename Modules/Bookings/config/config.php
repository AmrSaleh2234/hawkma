<?php

return [
    'slot_minutes' => (int) env('BOOKING_SLOT_MINUTES', 30),
    'duration_minutes' => (int) env('BOOKING_DURATION_MINUTES', 30),
    'min_notice_minutes' => (int) env('BOOKING_MIN_NOTICE_MINUTES', 60),
    'max_advance_days' => (int) env('BOOKING_MAX_ADVANCE_DAYS', 60),
    'payment_hold_minutes' => (int) env('BOOKING_PAYMENT_HOLD_MINUTES', 15),
    'client_cancel_hours' => (int) env('BOOKING_CLIENT_CANCEL_HOURS', 24),
];
