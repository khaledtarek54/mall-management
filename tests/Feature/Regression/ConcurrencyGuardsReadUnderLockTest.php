<?php

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use Tests\Support\LockSpy;

/**
 * The double-booking and over-allocation guards READ under a lock, not merely wait behind one.
 *
 * **F-09 / F-10, pre-staging QA 2026-08-19, found with two PHP processes on two MySQL connections.**
 *
 * `LeaseCreationService` takes `Unit::lockForUpdate()` and its comment said this made the
 * `isActivelyLeased()` guard *"authoritative"*. It did not. Under MySQL REPEATABLE READ a
 * transaction's consistent-read snapshot is established at its FIRST plain read — the tenant lookup,
 * one line above the lock — and every later plain read is served from it. So the lock correctly
 * serialised the two writers, and the guard behind it still could not see the lease the other one
 * had just committed. Measured, at the same instant in the second transaction:
 *
 *     guard saw isActivelyLeased=false (count 0); after refresh=false     ← plain read
 *     snapshot read sees 0 · LOCKING read sees 1                          ← lockForUpdate()
 *
 * `Payment::assertInvoicesNotOverAllocated()` had the identical shape: it locked the invoice ROWS
 * and then summed the `invoice_payment` pivot with a plain read.
 *
 * Nothing was corrupted, because the UNIQUE index on the document number refused the second insert
 * — but only by accident: both writers computed the SAME number from the same stale snapshot. The
 * operator got a duplicate-key 500 instead of the intended business refusal, and the moment
 * numbering became properly concurrent (F-10, fixed in the same change) the guards would have been
 * the only defence.
 *
 * ## What this test can and cannot prove
 *
 * **It cannot prove serialisation.** `SQLiteGrammar::compileLock()` returns `''`, the suite runs on
 * sqlite `:memory:`, and a single connection never interleaves. `LockSpy` compiles the lock to a SQL
 * comment so `DB::listen()` can see WHICH tables a path locked on the real call path — which is
 * enough to fail if the lock is removed, and is not enough to prove two transactions order
 * correctly.
 *
 * The end-to-end proof is the two-process race harness in `docs/qa/scripts/` (`race.sh lease`,
 * `race.sh payment`), which must be re-run against MySQL before staging. After the fix both races
 * ended in the intended refusal — *"This unit already has an active lease"* and *"Allocation to
 * invoice … cannot exceed EGP 0.00"* — rather than a duplicate-key error.
 */
beforeEach(function () {
    seedRoles();

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'K-1']);
    $this->tenant = makeTenant();
});

it('locks the lease rows when deciding whether a unit is free', function () {
    // The unit row lock was already there and is not what was missing; `leases` is. Asserting the
    // lease-side lock is what fails if `isActivelyLeasedForUpdate()` reverts to a plain read.
    $spy = LockSpy::watch(function () {
        app(LeaseCreationService::class)->create([
            'tenant_mode' => 'existing',
            'tenant_id' => $this->tenant->id,
            'lease' => [
                'unit_id' => $this->unit->id,
                'commencement_date' => '2026-09-01',
                'term_months' => 12,
                'base_rent_monthly' => 50_000,
                'service_charge_monthly' => 0,
            ],
        ]);
    });

    expect($spy->locked('units'))->toBeTrue()
        ->and($spy->locked('leases'))->toBeTrue();
});

it('locks the settlement channels when checking an invoice for room', function () {
    $lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['total' => 10_000, 'balance' => 10_000]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 1_000,
        'payment_date' => now()->toDateString(),
        'method' => 'cash',
        'status' => 'captured',
    ]);

    $spy = LockSpy::watch(function () use ($payment, $invoice) {
        $payment->assertInvoicesNotOverAllocated([$invoice->id]);
    });

    // All four settlement channels, because the guard is only as strong as its weakest term: a
    // plain read of any one of them lets that channel's concurrent settlement go unseen.
    // `payments`, not `invoice_payment`: the pivot sum selects FROM payments joined to the pivot,
    // and the lock clause attaches to the statement's own table.
    expect($spy->locked('invoices'))->toBeTrue()
        ->and($spy->locked('payments'))->toBeTrue()
        ->and($spy->locked('tenant_credit_applications'))->toBeTrue()
        ->and($spy->locked('deposit_applications'))->toBeTrue();
});

it('allocates a payment reference under the document-number lock', function () {
    // F-10. `Payment` was the one money model carrying a UNIQUE reference that did not use the
    // trait: it had a retry loop, but the loop's existence check is a plain read, so two receipts
    // in the same second both computed `PAY-202608-0195` and one died on the index.
    expect(class_uses_recursive(Payment::class))->toContain(AllocatesDocumentNumber::class);
});

it('lets the model allocate a lease reference rather than pre-computing one', function () {
    // `Lease::creating` allocates under the lock and returns early when a reference is already
    // filled — so `LeaseCreationService` passing one bypassed the lock entirely.
    $source = file_get_contents(app_path('Services/LeaseCreationService.php'));

    expect($source)->not->toContain("'reference' => Lease::generateReference(");

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => $this->tenant->id,
        'lease' => [
            'unit_id' => $this->unit->id,
            'commencement_date' => '2026-09-01',
            'term_months' => 12,
            'base_rent_monthly' => 50_000,
            'service_charge_monthly' => 0,
        ],
    ]);

    // The control: dropping the pre-computed reference must still produce one.
    expect($lease->reference)->not->toBeEmpty()
        ->and($lease->reference)->toContain($this->asset->code);
});
