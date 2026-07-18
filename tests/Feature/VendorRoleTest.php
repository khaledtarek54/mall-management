<?php

use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-USR-03 — "Vendor accounts shall be view-only, with the specific exception of CSV upload."
 * FR-USR-02 restricts import to admins; the vendor is the one documented exception.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('makes the vendor role view-only on the maintenance surface, plus CSV upload', function () {
    $vendor = makeUser('vendor');

    // View-only on the work a contractor does (view_all so it sees the board of its malls).
    expect($vendor->can('maintenance.view'))->toBeTrue()
        ->and($vendor->can('maintenance.view_all'))->toBeTrue()
        ->and($vendor->can('preventive_maintenance.view'))->toBeTrue()
        ->and($vendor->can('notes.view'))->toBeTrue()
        // The ONE exception (FR-USR-03): CSV upload.
        ->and($vendor->can('imports.execute'))->toBeTrue();
});

it('gives the vendor role no write authority of any kind', function () {
    $vendor = makeUser('vendor');

    foreach ([
        'maintenance.create', 'maintenance.edit', 'maintenance.delete',
        'maintenance.assign', 'maintenance.change_status',
        'preventive_maintenance.create', 'preventive_maintenance.edit', 'preventive_maintenance.complete',
        'notes.create',
    ] as $perm) {
        expect($vendor->can($perm))->toBeFalse("vendor must not hold {$perm}");
    }
});

it('keeps another party\'s commercial data out of an external vendor\'s reach', function () {
    $vendor = makeUser('vendor');

    foreach ([
        'tenants.view', 'leases.view', 'invoices.view', 'payments.view',
        'payrolls.view', 'general_ledger.view', 'vendor_bills.view', 'reports.view',
    ] as $perm) {
        expect($vendor->can($perm))->toBeFalse("vendor must not read {$perm}");
    }
});
