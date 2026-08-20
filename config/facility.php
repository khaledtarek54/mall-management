<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Repeat-visit window
    |--------------------------------------------------------------------------
    |
    | A second job on the same machine (or the same shop, where no machine is
    | named) in the same trade, within this many days, is a REPEAT VISIT —
    | ServiceChannel's highest-value cheap signal, because it identifies the
    | fault that was never actually fixed and the contractor who keeps coming
    | back to bill twice.
    |
    | 30 days is the retail-FM convention. It is a judgement, not a law: too
    | short and a genuine recurrence reads as a new fault, too long and ordinary
    | wear on a busy machine reads as a failure to fix.
    |
    | Config rather than a setting because it changes about never, and a number
    | on a settings screen invites tuning until the signal says whatever the
    | reader wanted.
    |
    */
    'repeat_visit_days' => 30,
];
