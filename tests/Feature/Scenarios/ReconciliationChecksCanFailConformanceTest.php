<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Every books check must be ABLE to fail. A check that cannot is a check that measures storage.
 *
 * **The gap this closes (pre-staging QA, F-08).** `billing:reconcile`'s CAM tie-out asserted
 * `Σ allocated + landlord_unrecovered == total_actual_expense`. The generator writes
 * `landlord_unrecovered = actual − Σ allocated`, so that identity holds by construction whatever the
 * shares sum to — and a pool recovering **1,150,000 against 1,000,000 of actual common cost** passed
 * it cleanly. The check was not wrong about anything; it simply could not be wrong about anything.
 *
 * Fairly: it was not a pure tautology either. Mutation-testing showed it catches an allocation
 * tampered with *after* generation. What it could not see was an over-recovery the generator itself
 * produced — the case that matters, because that is the one nobody is watching for.
 *
 * That distinction is only visible by MUTATION. Reading the check tells you what it compares;
 * breaking the data tells you what it notices. So this gate perturbs each check's subject and
 * insists the check goes red — and, just as importantly, insists it is green on unperturbed data,
 * because a check that fails on everything is no better than one that fails on nothing.
 *
 * Not every check is covered: the GL tie-outs are cumulative and all-time, and building a fixture
 * that moves one without moving the others is a bigger job than the value it returns here. The four
 * below are the ones a wrong number reaches a tenant through.
 */
beforeEach(function () {
    seedRoles();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'R-1', 'area_sqm' => 250]);
    $this->lease = makeLease($this->unit, null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2035-12-31',
    ]);
    $this->invoice = makeInvoice($this->lease, [
        'total' => 10_000, 'subtotal' => 10_000, 'vat_amount' => 0,
        'paid_amount' => 0, 'balance' => 10_000, 'status' => 'issued',
    ]);

    $this->service = app(BooksReconciliationService::class);

    /** Run the reconciliation and return one named check. */
    $this->check = function (string $key): array {
        return collect($this->service->run(null, false)['checks'])->firstWhere('key', $key) ?? [];
    };
});

it('is green on unperturbed data — otherwise every assertion below is meaningless', function () {
    foreach (['balance', 'paid_amount', 'cam_allocations', 'deposits_tie_out'] as $key) {
        $check = ($this->check)($key);

        expect($check)->not->toBeEmpty("There is no check named [{$key}] any more — this gate is testing nothing.");
        expect($check['passed'])->toBeTrue(
            "[{$key}] fails on clean data: ".json_encode(array_slice($check['discrepancies'], 0, 2))
        );
    }
});

it('notices an invoice balance that does not match its own totals', function () {
    // `balance = total − paid_amount` is the AR invariant. Storing anything else is the shape the
    // whole reconciliation exists to find.
    $this->invoice->forceFill(['balance' => 1])->saveQuietly();

    expect(($this->check)('balance')['passed'])->toBeFalse('a wrong stored balance passed the balance check');

    $this->invoice->forceFill(['balance' => 10_000])->saveQuietly();
    expect(($this->check)('balance')['passed'])->toBeTrue();
});

it('notices a paid_amount that no settlement channel accounts for', function () {
    // Nothing was paid — no payment, no credit note, no tenant credit, no netted deposit — so a
    // non-zero `paid_amount` is money the books cannot explain.
    $this->invoice->forceFill(['paid_amount' => 4_000, 'balance' => 6_000])->saveQuietly();

    expect(($this->check)('paid_amount')['passed'])->toBeFalse('an unexplained paid_amount passed the check');

    $this->invoice->forceFill(['paid_amount' => 0, 'balance' => 10_000])->saveQuietly();
    expect(($this->check)('paid_amount')['passed'])->toBeTrue();
});

it('notices a CAM pool that recovers more than it spent', function () {
    // The case F-08 was: over-recovery. The check now compares Σ allocated against the pool expense
    // DIRECTLY, so storing the residual the generator would have written no longer conceals it.
    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'name' => 'Reconciliation probe',
        'period_year' => 2030,
        'total_actual_expense' => 1_000_000,
        'total_estimated_collected' => 0,
        'status' => 'draft',
        'estimate_basis' => 'stated',
        'recovery_vat_rate' => 14,
        'admin_fee_pct' => 0,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool->fresh());

    expect(($this->check)('cam_allocations')['passed'])->toBeTrue();

    $allocation = CamAllocation::where('cam_expense_pool_id', $pool->id)->firstOrFail();
    $allocation->forceFill(['allocated_amount' => (float) $allocation->allocated_amount + 250_000])->saveQuietly();
    // …and store the residual the generator WOULD have written, so the identity check balances.
    // Before F-08 this combination passed; that is the whole point of the assertion.
    $pool->forceFill(['landlord_unrecovered_amount' => -250_000])->saveQuietly();

    expect(($this->check)('cam_allocations')['passed'])->toBeFalse(
        'an over-recovering pool passed the CAM check — the tie-out is measuring its own residual again'
    );
});

it('notices a deposit that moved on one road and not the other', function () {
    depositMovement($this->lease, 'receipt', 90_000);

    // The register now holds money the ledger has never seen.
    expect(($this->check)('deposits_tie_out')['passed'])->toBeFalse('an unposted deposit passed the tie-out');

    $this->artisan('accounting:sync-ledger', ['--all' => true]);
    expect(($this->check)('deposits_tie_out')['passed'])->toBeTrue('the tie-out stayed red after the books caught up');
});
