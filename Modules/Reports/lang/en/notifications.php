<?php

return [
    'report_ready' => [
        'subject' => 'Your report is ready: :title',
        'greeting' => 'Hello :name,',
        'intro' => ':consultant uploaded the report ":title" for your booking.',
        'view' => 'View the report in your dashboard',
        'direct_download' => 'Or download it directly with this link (valid for 7 days):',
    ],
    'report_reviewed' => [
        'subject' => 'Report review update: :title',
        'greeting' => 'Hello :name,',
        'note' => 'Reviewer note: :note',
        'view' => 'Open the report',
        'approved' => [
            'line' => 'Your report ":title" was approved and sent to the client.',
        ],
        'rejected' => [
            'line' => 'Your report ":title" was rejected.',
        ],
        'returned' => [
            'line' => 'Your report ":title" was returned for revision. Please update it and upload it again.',
        ],
    ],
];
