<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Toggles for optional product surfaces. Flip the matching env var and
    | (in production) re-run `php artisan config:cache`.
    |
    */

    // Owner portal (/owner panel). Disabled by default — when off, the panel
    // provider isn't registered (routes + login absent) and the landing-page
    // card is hidden. The test suite forces this on (phpunit.xml) so the
    // owner-panel coverage keeps running.
    'owner_portal' => env('OWNER_PORTAL_ENABLED', false),

];
