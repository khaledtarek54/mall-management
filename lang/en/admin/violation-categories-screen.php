<?php

return [
    'violation_categories_screen' => [
        'singular' => 'Violation category',
        'plural' => 'House rules',
        'recorded' => 'Recorded',
        'no_fine' => 'No standard fine',
        'help' => [
            'code' => 'Stored on every violation recorded under it. Cannot change once saved.',
            'default_fine' => 'Prefills the fine when this rule is picked. The officer can still change it.',
            'sort_order' => 'Lower numbers appear first in the officer\'s picker.',
            'is_active' => 'Switching off hides it from new violations; breaches already recorded are unchanged.',
        ],
    ],
];
