<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maintenance request SLA targets
    |--------------------------------------------------------------------------
    |
    | Hours from submission within which a request of each priority should be
    | resolved. Used to compute `target_resolution_at` on create and to flag
    | breached items on the admin dashboard.
    |
    */
    'sla' => [
        'urgent' => ['resolve_hours' => 24],
        'high'   => ['resolve_hours' => 72],
        'medium' => ['resolve_hours' => 168],   // 7 days
        'low'    => ['resolve_hours' => 336],   // 14 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-close window
    |--------------------------------------------------------------------------
    |
    | Days after `resolved_at` before a maintenance request is automatically
    | considered closed in dashboards / filters. The actual status flip can be
    | wired via a scheduled job later.
    |
    */
    'auto_close_after_days' => 7,
];
