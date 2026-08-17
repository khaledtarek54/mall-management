<?php

use App\Models\Tenant;

/**
 * What the operator's tenant list says about money (module 02 close-out).
 *
 * The delinquency badge said *whether* a tenant was overdue and never *how much* — so EGP 500 and
 * EGP 500,000 rendered identically to whoever was deciding who to call first. The tenant could
 * already see the figure on the portal and through the API; the operator's own list was the one
 * place it was missing.
 *
 * **The property scoping is the part that must not break.** A tenant trading in two malls has one
 * `tenants` row, and an operator who can only see mall A must never be shown mall B's arrears —
 * the same cross-property AR leak the badge itself is scoped against.
 */
it('reports what a tenant owes, scoped to the properties in view', function () {
    $mallA = makeAsset();
    $mallB = makeAsset();

    $tenant = makeTenant();
    $leaseA = makeLease(makeUnit($mallA, ['code' => 'A-1']), $tenant, ['status' => 'active']);
    $leaseB = makeLease(makeUnit($mallB, ['code' => 'B-1']), $tenant, ['status' => 'active']);

    makeInvoice($leaseA, ['status' => 'overdue', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000]);
    makeInvoice($leaseB, ['status' => 'overdue', 'subtotal' => 90000, 'vat_amount' => 0, 'total' => 90000, 'balance' => 90000]);

    // Portfolio-wide: both.
    expect($tenant->fresh()->outstandingBalance())->toBe(95000.0);

    // A mall-A operator sees mall A only — mall B's 90,000 must not leak.
    expect($tenant->fresh()->outstandingBalance([$mallA->id]))->toBe(5000.0);
});

it('shows nothing rather than zero for a tenant who owes nothing', function () {
    // The description is suppressed at zero: "EGP 0.00" under a green "Current" badge is noise.
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset(), ['code' => 'C-1']), $tenant, ['status' => 'active']);

    expect($tenant->fresh()->outstandingBalance())->toBe(0.0);
});
