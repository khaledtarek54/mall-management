<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Near-real-time general-ledger posting
    |--------------------------------------------------------------------------
    |
    | When on, every posting source dispatches a queued SyncDocumentToLedger job
    | (afterCommit) on save / delete / restore, so its journal entry reconciles
    | within seconds instead of waiting for the daily `accounting:sync-ledger`
    | sweep. The daily sweep + weekly `--all` remain the backstop either way.
    |
    | Disabled under the test suite, which drives sync / the sweep explicitly (so
    | tests assert deterministic posting rather than racing an async job).
    |
    */

    'realtime_ledger_sync' => env('ACCOUNTING_REALTIME_LEDGER_SYNC', true),

];
