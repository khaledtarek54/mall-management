<?php

return [
    'tenant_request_subcategories' => [
        'singular' => 'Reportable problem',
        'plural' => 'What tenants can report',
        'help' => [
            'request_type' => 'Which kind of request this appears under. Cannot change once saved.',
            'code' => 'Stored on every request. Unique within its type; cannot change once saved.',
            'trade' => 'The trade a work order raised from this should go to. Leave blank if it is not a maintenance fault.',
            'is_active' => 'Switching off hides it from the tenant; requests already using it are unchanged.',
        ],
    ],
];
