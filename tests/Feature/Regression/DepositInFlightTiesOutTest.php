<?php

use App\Services\BillSecurityDepositService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\DepositHoldings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A security deposit that has been billed and not yet paid is not a books discrepancy.
 *
 * **F-11, pre-staging QA 2026-08-19.** Two definitions, each correct, compared directly:
 *
 *  - `InvoiceJournalizer` credits `deposits_held` at ISSUE (Dr Tenant Receivables / Cr Deposits
 *    Held) — the documented, correct entry for the billing rail;
 *  - `DepositHoldings::held()` counts a billed deposit only once it is SETTLED — also deliberate,
 *    because an unpaid deposit is a receivable and refunding it at move-out would give back money
 *    that never arrived.
 *
 * So for the whole window between issuing a deposit invoice and its payment the ledger legitimately
 * runs ahead of the register, and `deposits_tie_out` reported it as drift. Measured: a 150,000
 * deposit billed and unpaid moved the GL from 973,335 to 1,123,335 while `held` stayed put, and the
 * check failed until the payment landed. `billing:reconcile --deep` runs weekly on a Friday and
 * terms are typically 7 days, so a deposit billed on a Thursday failed it every time — and a check
 * that cries wolf is a check people switch off.
 *
 * The tie-out now compares against `expectedGlBalance()` = held + billed-and-outstanding. The second
 * test is the one that matters most: the check must still catch a REAL gap, or the fix would have
 * traded a false alarm for a blind spot.
 */
beforeEach(function () {
    seedRoles();
    // The tie-out compares against the LEDGER, so there has to be one. Without the chart,
    // `glBalance()` is null, `depositTieOutDiscrepancies()` returns early, and every assertion here
    // would pass for the wrong reason.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'D-1']);
    $this->lease = makeLease($this->unit, null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'security_deposit' => 150_000,
    ]);
});

it('does not report a deposit that has been billed and not yet paid', function () {
    $service = app(BooksReconciliationService::class);

    expect($service->depositTieOutDiscrepancies())->toBeEmpty();

    app(BillSecurityDepositService::class)->bill($this->lease->fresh());
    $this->artisan('accounting:sync-ledger', ['--all' => true]);

    expect(DepositHoldings::billedAndOutstanding())->toBe(150_000.0)
        // Held is unchanged — nothing has arrived, and that reading stays correct.
        ->and(DepositHoldings::held())->toBe(0.0)
        // …but the ledger has recognised the liability, and the expectation now says so.
        ->and(DepositHoldings::expectedGlBalance())->toBe((float) DepositHoldings::glBalance())
        ->and($service->depositTieOutDiscrepancies())->toBeEmpty();
});

it('still reports a deposit that moved on one road and not the other', function () {
    // The control that stops this fix becoming a blind spot: a receipt recorded in the register
    // with nothing in the books must still be caught.
    $service = app(BooksReconciliationService::class);

    depositMovement($this->lease, 'receipt', 90_000);

    expect($service->depositTieOutDiscrepancies())->not->toBeEmpty();

    $this->artisan('accounting:sync-ledger', ['--all' => true]);

    expect($service->depositTieOutDiscrepancies())->toBeEmpty();
});

it('moves the deposit from in-flight to held when the tenant pays', function () {
    $invoice = app(BillSecurityDepositService::class)->bill($this->lease->fresh());

    settleInvoiceInFull($invoice->fresh());
    $this->artisan('accounting:sync-ledger', ['--all' => true]);

    expect(DepositHoldings::billedAndOutstanding())->toBe(0.0)
        ->and(DepositHoldings::held())->toBe(150_000.0)
        ->and(app(BooksReconciliationService::class)->depositTieOutDiscrepancies())->toBeEmpty();
});
