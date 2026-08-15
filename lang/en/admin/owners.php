<?php

return [
    'owner_requests' => [
        // The three priorities an owner can set. Previously manufactured from the column value
        // by Str::headline(), which produces an English word no translation gate can see.
        'priorities' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
        ],
        'conversation' => 'Conversation',
        'replies' => 'Replies',
        'set_status' => 'Set status (optional)',
        'set_status_hint' => 'Leave unchanged to just reply. Resolving or closing marks the request done.',
        'unknown_author' => 'Unknown',
        'actions' => [
            'reply' => 'Reply',
            'send' => 'Send reply',
            'your_reply' => 'Your reply',
        ],
        'statuses' => [
            'open' => 'Open',
            'in_progress' => 'In progress',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled',
        ],
        'notices' => [
            'replied' => 'Reply sent',
            'replied_body' => 'Your reply is on the thread — status: :status.',
        ],
        'errors' => [
            'terminal' => 'This request is closed and can no longer be replied to.',
            'empty_reply' => 'Write a reply before sending.',
        ],
    ],
];
