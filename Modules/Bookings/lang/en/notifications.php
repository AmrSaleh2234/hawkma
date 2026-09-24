<?php

return [
    'booking_confirmed' => [
        'subject' => 'Your booking :reference is confirmed',
        'greeting' => 'Hello :name,',
        'intro' => 'Your booking with :consultant on :date at :time (Riyadh time) under the :package package is confirmed.',
        'location' => 'Location: :location',
        'join' => 'Join the meeting',
        'link_later' => 'The meeting link will be sent later.',
        'amount' => 'Amount paid: :amount',
    ],
    'new_booking' => [
        'subject' => 'New booking :reference',
        'greeting' => 'Hello :name,',
        'intro' => 'You have a new booking from :company on :date at :time (Riyadh time).',
        'join' => 'Meeting link',
    ],
    'booking_cancelled' => [
        'subject' => 'Booking :reference was cancelled',
        'greeting' => 'Hello :name,',
        'intro' => [
            'client' => 'Your booking :reference scheduled on :date at :time was cancelled.',
            'consultant' => 'The booking :reference with :company scheduled on :date at :time was cancelled.',
        ],
        'reason' => 'Reason: :reason',
    ],
];
