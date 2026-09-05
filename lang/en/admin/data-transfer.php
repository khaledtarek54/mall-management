<?php

/**
 * What an operator is told when a bulk import or export finishes.
 *
 * ONE SENTENCE PER TRANSFER, written out rather than composed from a noun and a template: Arabic
 * governs a noun by definiteness and case, so a single «اكتمل استيراد :records» is right for half
 * of this list and wrong for the other half. Which sentence a transfer uses is DERIVED from its
 * class name (`LedgerAccountImporter` → `import.ledger_account`), so a new importer needs a key
 * here and in `lang/ar/admin/data-transfer.php` — and no code.
 *
 * `:rows` and not `:count`: `Translator::choice()` overwrites `count` with the raw number after the
 * caller's replacements are merged, so a thousands-separated figure passed as `count` is discarded
 * and a 12,500-row import reports "12500".
 *
 * See App\Support\DataTransferNotice.
 */
return [

    'data_transfer' => [

        // The heading sentence; the row counts follow it.
        'import' => [
            'charge' => 'Your charge-schedule import has completed.',
            'employee' => 'Your employee import has completed.',
            'equipment' => 'Your equipment import has completed.',
            'fixed_asset' => 'Your fixed-asset import has completed.',
            'lease' => 'Your lease import has completed.',
            'ledger_account' => 'Your chart of accounts import has completed.',
            'meter_reading' => 'Your meter-reading import has completed.',
            'opening_invoice' => 'Your opening-balance import has completed.',
            'tenant' => 'Your tenant import has completed.',
            'unit' => 'Your unit import has completed.',
            'unit_ownership' => 'Your unit-ownership import has completed.',
            'vendor' => 'Your vendor import has completed.',
        ],

        'export' => [
            'asset' => 'Your property export has completed.',
            'credit_note' => 'Your credit note export has completed.',
            'invoice' => 'Your invoice export has completed.',
            'lease' => 'Your lease export has completed.',
            'payment' => 'Your payment export has completed.',
            'tenant' => 'Your tenant export has completed.',
            'tenant_request' => 'Your request export has completed.',
            'unit' => 'Your unit export has completed.',
            'vendor' => 'Your vendor export has completed.',
        ],

        'rows' => [
            'import' => '{0}No rows were imported.|{1}One row was imported.|[2,*]:rows rows were imported.',
            'export' => '{0}No rows were exported.|{1}One row was exported.|[2,*]:rows rows were exported.',
        ],

        // Rendered only when something failed.
        'failed' => [
            'import' => '{1}One row failed to import.|[2,*]:rows rows failed to import.',
            'export' => '{1}One row failed to export.|[2,*]:rows rows failed to export.',
        ],

        // The two transfers that leave something to check afterwards.
        'followup' => [
            'import' => [
                'charge' => 'Run `php artisan atriom:audit-charge-schedules` to confirm no lease was left with an overlapping or gapped schedule.',
                'opening_invoice' => 'Now run `php artisan billing:reconcile` — the AR tie-out is what proves the figures match your accountant\'s opening trial balance.',
            ],
        ],
    ],
];
