<?php

use Database\Seeders\RolesPermissionsSeeder;

/**
 * FR-USR-03 — "Vendor accounts shall be view-only, with the specific exception of CSV upload."
 * FR-USR-02 restricts import to admins; the vendor is the one documented exception.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('makes the vendor role view-only on the maintenance surface', function () {
    $vendor = makeUser('vendor');

    // View-only on the work a contractor does (view_all so it sees the board of its malls).
    expect($vendor->can('requests.view'))->toBeTrue()
        ->and($vendor->can('requests.view_all'))->toBeTrue()
        ->and($vendor->can('facility.view'))->toBeTrue()
        ->and($vendor->can('notes.view'))->toBeTrue()
        // FR-USR-03's "CSV upload" exception is DEFERRED — deliberately NOT the blanket admin
        // `imports.execute` (that is tenants/leases/units import, which widens the import-admins-only
        // gate FR-USR-02 / ImportIsAdminOnlyTest enforces). A vendor upload needs its own surface.
        ->and($vendor->can('imports.execute'))->toBeFalse();
});

it('gives the vendor role no write authority of any kind', function () {
    $vendor = makeUser('vendor');

    foreach ([
        'requests.create', 'requests.edit', 'requests.delete',
        'requests.assign', 'requests.change_status',
        'facility.create', 'facility.edit', 'facility.complete',
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
