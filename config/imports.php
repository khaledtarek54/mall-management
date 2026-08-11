<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import execution
    |--------------------------------------------------------------------------
    |
    | The three importers (Tenant, Unit, Lease) each hard-coded `return 'sync'`,
    | so configuration could not reach them — and the cut-over, the largest
    | import this system will ever run, executed inside one HTTP request.
    |
    | `sync` stays the DEFAULT so local work and the test suite keep running
    | inline and deterministically. Production sets IMPORT_QUEUE_CONNECTION to
    | the real queue, which is the whole point of it being configurable.
    |
    | Mirrors config/exports.php, which already had this shape.
    |
    */

    'connection' => env('IMPORT_QUEUE_CONNECTION', 'sync'),

    /*
    | A guard rail against a mis-mapped file, not a capacity limit. A CSV whose
    | columns landed in the wrong places should stop at a legible error rather
    | than write thousands of wrong rows. Raise it deliberately for a big
    | cut-over — and prefer raising it to removing it.
    */
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),

];
