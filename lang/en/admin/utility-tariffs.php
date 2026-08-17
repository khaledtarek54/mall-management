<?php

/**
 * Module 10 — the utility price list. Its own partial because `admin.php` merges partials with
 * `+=` and THROWS on a duplicate top-level key, so a new domain gets a new file rather than being
 * appended to somebody else's.
 */
return [
    'utility_tariffs' => [
        'sections' => [
            'identity' => 'Tariff',
            'identity_description' => 'What this price list is called and which utility it prices. The prices themselves go on the ladder below, once the tariff is saved.',
        ],

        'rate_ladder' => 'Price ladder',
        'add_rate' => 'Add a price',
        'scheduled' => 'Scheduled',
        'current_rate' => 'Price today',
        'no_rate_yet' => 'No price entered — meters on this tariff cost 0 and cannot be billed',
        'scheduled_change' => 'Next change',
        'meters_priced' => 'Meters',
    ],
];
