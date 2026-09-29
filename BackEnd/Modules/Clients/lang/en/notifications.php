<?php

return [
    'welcome' => [
        'subject' => 'Welcome to GCMC',
        'greeting' => 'Hello :name,',
        'intro' => 'Your account has been created successfully. You can now book consultations and manage your subscriptions from your dashboard.',
        'action' => 'Go to your dashboard',
    ],
    'reset_password' => [
        'subject' => 'Reset your password',
        'intro' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset Password',
        'expire' => 'This password reset link will expire in :count minutes.',
        'outro' => 'If you did not request a password reset, no further action is required.',
    ],
    'new_ticket' => [
        'subject' => 'New support ticket: :subject',
        'greeting' => 'Hello :name,',
        'intro' => 'Client :client opened a new ticket (:type) titled ":subject".',
        'action' => 'View ticket',
    ],
    'ticket_client_reply' => [
        'subject' => 'New client reply: :subject',
        'greeting' => 'Hello :name,',
        'intro' => 'Client :client added a new reply to ticket ":subject".',
        'action' => 'View ticket',
    ],
    'ticket_reply' => [
        'subject' => 'New reply on your ticket: :subject',
        'greeting' => 'Hello :name,',
        'intro' => ':replier from the support team replied to your ticket ":subject".',
        'action' => 'View ticket',
    ],
];
