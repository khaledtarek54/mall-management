<?php

use App\Models\Expense;
use App\Models\PostDatedCheque;

/**
 * Pre-go-live deletion-policy review — a property / lease / tenant that carries a money record is
 * NOT deletable.
 *
 * THE GAP. DeletionPolicy's WHEN_UNUSED `blocked_by` lists omitted the financial dimension:
 *  - Asset blocked only on units/leases/camPools/utilityMeters. Its money/HR/GL children carry a
 *    direct asset_id whose FK is cascadeOnDelete, so a force-delete of a property with financial
 *    history DESTROYED them outright — including a NEVER-deletable MaintenancePenalty — bypassing
 *    every model guard, and even a soft-delete stranded the books.
 *  - Lease omitted deposits + post-dated cheques (both NEVER money, both can exist before any invoice).
 *  - Tenant omitted post-dated cheques.
 * The conformance test can't catch this class (it checks the LISTED relations exist, not that ALL
 * history-bearing ones are listed). Fixed by adding the relations + blocked_by entries.
 */
it('refuses to delete a property that carries financial history (was force-delete-destroyable)', function () {
    // The reachable case: a pre-opening / newly-onboarded property — financial setup before any unit.
    $asset = makeAsset();
    Expense::create([
        'asset_id' => $asset->id, 'category' => 'admin', 'amount' => 1000, 'vat_amount' => 0,
        'total' => 1000, 'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    expect($asset->fresh()->isDeletableNow())->toBeFalse()
        ->and(fn () => $asset->fresh()->delete())->toThrow(DomainException::class);
});

it('still deletes a brand-new property that carries nothing (the fix does not over-block)', function () {
    $asset = makeAsset();

    expect($asset->fresh()->isDeletableNow())->toBeTrue();
    $asset->fresh()->delete(); // no throw
    expect($asset->fresh()->trashed())->toBeTrue();
});

it('refuses to delete a lease that holds a post-dated cheque (a NEVER money record, pre-invoice)', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(), 'asset_id' => $lease->unit->asset_id,
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'cheque_number' => 'CHQ-'.uniqid(),
        'amount' => 3000, 'cheque_date' => now()->addMonth()->toDateString(),
        'received_date' => now()->toDateString(), 'status' => 'held',
    ]);

    expect(fn () => $lease->fresh()->delete())->toThrow(DomainException::class);
});

it('refuses to delete a tenant that holds a lodged post-dated cheque', function () {
    // A lease-less tenant, so the ONLY thing pointing at it is the cheque — isolates the PDC gap
    // (a tenant with a lease would be blocked by `leases` regardless).
    $tenant = makeTenant();
    $asset = makeAsset();
    PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(), 'asset_id' => $asset->id,
        'tenant_id' => $tenant->id, 'cheque_number' => 'CHQ-'.uniqid(), 'amount' => 3000,
        'cheque_date' => now()->addMonth()->toDateString(), 'received_date' => now()->toDateString(), 'status' => 'held',
    ]);

    expect($tenant->fresh()->isDeletableNow())->toBeFalse()
        ->and(fn () => $tenant->fresh()->delete())->toThrow(DomainException::class);
});
