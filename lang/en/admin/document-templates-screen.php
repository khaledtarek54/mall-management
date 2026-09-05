<?php

return [
    'document_templates_screen' => [
        'singular' => 'Document wording',
        'plural' => 'Document wording',
        'house_default' => 'All properties (house default)',
        'languages' => 'Written in',
        'blocks' => [
            'invoice_footer' => 'Invoice — footer',
            'invoice_payment_instructions' => 'Invoice — how to pay',
            'invoice_terms' => 'Invoice — terms',
            'lease_agreement_terms' => 'Lease agreement — standing terms',
            'invoice_email_body' => 'Invoice email — covering note',
            'dunning_overdue_reminder' => 'Overdue reminder — email',
            'dunning_overdue_subject' => 'Overdue reminder — subject line',
            'dunning_final_notice' => 'Final demand — email',
            'dunning_final_subject' => 'Final demand — subject line',
            'dunning_late_fee_subject' => 'Late fee charged — subject line',
            'receipt_payment_subject' => 'Payment received — subject line',
            'lease_expiry_subject' => 'Lease expiring soon — subject line',
            'dunning_late_fee_applied' => 'Late fee charged — email',
            'receipt_payment_received' => 'Payment received — email',
            'lease_expiry_approaching' => 'Lease expiring soon — email',
        ],
        'sections' => [
            'which' => 'Which block',
            'wording' => 'Your wording',
            'wording_description' => 'Plain text. Line breaks are kept. Leave a language empty and the other one is used.',
        ],
        'help' => [
            'key' => 'Where this text appears. One block per property; the block cannot be changed later.',
            'asset' => 'Leave blank for every property. Pick one to override the house wording for that mall.',
            'body' => 'The invoice footer may use {days} — it becomes the payment terms in days.',
            'is_active' => 'Switching off falls the document back to the house default, or to the built-in wording.',
        ],
    ],
];
