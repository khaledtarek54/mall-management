<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto-close window
    |--------------------------------------------------------------------------
    |
    | Days after `resolved_at` before a tenant request is automatically closed by
    | `requests:auto-close`. Without it, "open" stops meaning "current work" —
    | resolved requests pile up in the board forever (audit M09 F-38 / D-30).
    |
    */
    'auto_close_after_days' => 7,
];
