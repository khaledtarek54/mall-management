<?php

return [
    'payment_methods' => [
        'singular' => 'Payment rail',
        'plural' => 'Payment rails',
        'floor' => ':role (default)',
        'help' => [
            'code' => 'The value stored on every document. Cannot change once saved.',
            'ledger_account' => 'Leave blank to use the default: cash to Cash, everything else to Bank.',
            'for_inbound' => 'Offered when recording money received.',
            'for_outbound' => 'Offered when paying a vendor, an expense or a payout.',
            'settlement_days' => 'Days until the money actually reaches the bank.',
            'is_active' => 'Switching off hides it from pickers; documents already using it are unchanged.',
        ],
    ],
];
