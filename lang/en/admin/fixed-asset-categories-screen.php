<?php

return [
    'fixed_asset_categories_screen' => [
        'singular' => 'Asset class',
        'plural' => 'Asset classes',
        'registered' => 'Registered',
        'life_and_rate' => ':months months · :rate% a year',
        'help' => [
            'code' => 'Stored on every asset registered under it. Cannot change once saved.',
            'tag_prefix' => 'Letters in front of the asset number: FUR gives FUR-0001, FUR-0002 … per property. Derived from the code if left blank.',
            'default_useful_life' => 'Proposed on a new asset of this class; the operator can change it there.',
            'default_salvage' => 'The memo value a new asset is proposed with. 1.00 keeps a fully-depreciated asset on the register at one pound rather than at nil.',
            'default_tax_pool' => 'The Law 91/2005 pool a new asset of this class is proposed in.',
            'sort_order' => 'Lower numbers appear first in the asset form\'s picker.',
            'is_active' => 'Switching off hides it from new assets; assets already registered are unchanged.',
        ],
    ],
];
