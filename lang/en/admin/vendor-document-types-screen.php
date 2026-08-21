<?php

return [
    'vendor_document_types_screen' => [
        'singular' => 'Vendor document type',
        'plural' => 'Supplier document types',
        'on_file' => 'On file',
        'blocks_dispatch_yes' => 'A lapse of this document stops the vendor being sent to site.',
        'blocks_dispatch_no' => 'A lapse is chased but does not stop site work.',
        'help' => [
            'code' => 'Stored on every document filed under it. Cannot change once saved.',
            'blocks_dispatch' => 'Once lapsed, the vendor disappears from every assignment picker until it is renewed.',
            'sort_order' => 'Lower numbers appear first when filing a document.',
            'is_active' => 'Switching off hides it when filing; documents already on file are unchanged.',
        ],
    ],
];
