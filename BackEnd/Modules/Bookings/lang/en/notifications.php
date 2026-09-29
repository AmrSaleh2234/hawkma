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
    'cant_attend' => [
        'subject' => 'Unable-to-attend request :reference',
        'greeting' => 'Hello :name,',
        'intro' => 'Consultant :consultant raised an unable-to-attend request for booking :reference scheduled on :date at :time.',
        'reason' => 'Reason: :reason',
    ],
    'booking_reassigned' => [
        'subject' => 'Booking :reference was reassigned',
        'greeting' => 'Hello :name,',
        'intro' => [
            'client' => 'Your booking :reference on :date at :time was reassigned to consultant :new (instead of :old).',
            'old_consultant' => 'Booking :reference scheduled on :date at :time was reassigned to consultant :new.',
            'new_consultant' => 'You were assigned booking :reference with :company on :date at :time (instead of :old).',
        ],
    ],
];
