<?php

return [

    /*
     * Where operational notifications go. OFF unless set — nothing is posted, nothing fails.
     *
     * ONE webhook, deliberately. Splitting alerts across several channels is how the quiet one
     * stops being read; if that changes, add a second key here rather than a second poster.
     */
    'webhook_url' => env('DISCORD_WEBHOOK_URL'),

    /*
     * Shown as the sender. Set it per environment — an alert that does not say WHICH box it came
     * from is worse than none, because the reader assumes production.
     */
    'username' => env('DISCORD_USERNAME', env('APP_NAME', 'Atriom').' '.env('APP_ENV', 'local')),

    'timeout' => (int) env('DISCORD_TIMEOUT', 5),

];
