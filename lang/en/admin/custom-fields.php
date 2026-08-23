<?php

return [
    'custom_fields' => [
        // The section that appears on an extended record's form and record page.
        'section' => 'Additional information',
        'section_help' => 'Fields your organisation added to this record type.',
        'yes' => 'Yes',
        'no' => 'No',

        // The screen where the definitions are managed.
        'singular' => 'Custom field',
        'plural' => 'Custom fields',
        'model' => 'Applies to',
        'key' => 'Field key',
        'label_en' => 'Label (English)',
        'label_ar' => 'Label (Arabic)',
        'type' => 'Field type',
        'options' => 'Choices',
        'option_value' => 'Stored value',
        'is_required' => 'Required',
        'add_option' => 'Add a choice',
        'types' => [
            'text' => 'Text',
            'textarea' => 'Long text',
            'number' => 'Number',
            'date' => 'Date',
            'select' => 'Choice',
            'boolean' => 'Yes / no',
        ],
        'models' => [
            'tenant' => 'Tenants',
            'lease' => 'Leases',
            'unit' => 'Units',
            'vendor' => 'Vendors',
            'asset' => 'Properties',
        ],
        'help' => [
            'key' => 'Stored on every record answered under it. Cannot change once saved.',
            'model' => 'Which record type grows this field. Cannot change once saved.',
            'options' => 'One row per choice. The stored value is what reports and exports read.',
            'is_required' => 'The record cannot be saved without an answer.',
            'is_active' => 'Switching off stops it being asked; answers already recorded are kept and still shown.',
            'sort_order' => 'Lower numbers appear first on the form.',
        ],
    ],
];
