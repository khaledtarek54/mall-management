<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-party integration toggles
    |--------------------------------------------------------------------------
    |
    | When false, the corresponding admin/portal action is hidden so the
    | demo doesn't show stub buttons that flash a notification but don't
    | actually integrate yet. Flip to true once you've wired credentials.
    |
    */

    'paymob' => [
        'enabled' => env('PAYMOB_ENABLED', false),
    ],

    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
    ],

];
