<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export job connection
    |--------------------------------------------------------------------------
    |
    | Connection used by Filament CSV/Excel exports. 'sync' runs them in-request
    | — works without a queue worker, fine for dev and small datasets. In
    | production with a running queue worker, set EXPORT_QUEUE_CONNECTION to the
    | queue connection (e.g. 'database') so a full year of invoices doesn't time
    | out the request at 10k+ rows.
    |
    */

    'connection' => env('EXPORT_QUEUE_CONNECTION', 'sync'),

];
