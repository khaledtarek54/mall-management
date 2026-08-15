<?php

use App\Models\AccountMapping;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\PostDatedCheque;
use App\Models\Violation;

/**
 * Pre-go-live deletion-policy review — a property / lease / tenant that carries a money record is
 * NOT deletable.
 *
 * THE GAP. DeletionPolicy's WHEN_UNUSED `blocked_by` lists omitted the financial dimension:
 *  - Asset blocked only on units/leases/camPools/utilityMeters. Its money/HR/GL children carry a
 *    direct asset_id whose FK is cascadeOnDelete, so a force-delete of a property with financial
 *    history DESTROYED them outright — including a NEVER-deletable SlaPenalty — bypassing
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

/*
|--------------------------------------------------------------------------
| Config-reference gaps (second review pass)
|--------------------------------------------------------------------------
| Same class of under-population, one tier down. Each child's FK already refuses or nulls at the
| database, so the record was never DESTROYED — but the operator got a raw SQL constraint error
| (restrictOnDelete) or a silent un-assignment (nullOnDelete) instead of the friendly "deactivate
| instead" refusal. Adding the relation + blocked_by entry makes the refusal clean and consistent.
|
| `Vendor::purchaseRequests` was CONSIDERED and deliberately NOT added — a PR's vendor_id is
| nullable + nullOnDelete by design (a pre-award hint, not a commitment; the VendorBill is what
| blocks), so no regression test guards a non-blocker.
*/
it('refuses to delete a tenant whose only history is a violation (restrictOnDelete → clean refusal)', function () {
    // A lease-less tenant, so `violations` is the ONLY thing pointing at it — the violations table
    // has no lease_id, so this state is genuinely reachable.
    $tenant = makeTenant();
    $asset = makeAsset();
    Violation::create([
        'asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'category' => 'safety',
        'description' => 'Blocked fire exit', 'fine_amount' => 1000,
        'violation_date' => now()->toDateString(), 'status' => 'open',
    ]);

    expect($tenant->fresh()->isDeletableNow())->toBeFalse()
        ->and(fn () => $tenant->fresh()->delete())->toThrow(DomainException::class);
});

it('refuses to delete a ledger account that is a mapping target but never posted to', function () {
    // Deletable under the lines/children blockers alone (no journal lines, no children) — but it IS
    // a posting target, and deleting it would fail on the account_mappings restrictOnDelete FK.
    $account = LedgerAccount::create([
        'code' => 'RGN-'.uniqid(), 'name_en' => 'Regression target', 'name_ar' => 'هدف',
        'type' => 'revenue', 'is_postable' => true,
    ]);
    AccountMapping::updateOrCreate(
        ['key' => 'regression_probe', 'asset_id' => null],
        ['ledger_account_id' => $account->id],
    );

    expect($account->fresh()->isDeletableNow())->toBeFalse()
        ->and(fn () => $account->fresh()->delete())->toThrow(DomainException::class);
});

it('refuses to delete a department that still has HR employees (nullOnDelete → silent un-assign)', function () {
    // `members` are app Users; `employees` are HR staff — a different dimension the list omitted.
    // The FK is nullOnDelete, so without the blocker the department deletes and silently strips
    // department_id off its staff.
    $asset = makeAsset();
    $department = Department::factory()->create();
    Employee::create([
        'asset_id' => $asset->id, 'department_id' => $department->id, 'code' => 'E-'.uniqid(),
        'name' => 'Mona Adel', 'hire_date' => now()->toDateString(), 'base_salary' => 6000,
        'payment_method' => 'bank',
    ]);

    expect($department->fresh()->isDeletableNow())->toBeFalse()
        ->and(fn () => $department->fresh()->delete())->toThrow(DomainException::class);
});
