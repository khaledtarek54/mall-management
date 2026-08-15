<?php

use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A move-out settlement may not post into a closed month.
 *
 * `PostingDateGuards` exempted `DepositApplication` with `system:` — *"entry_date is stamped at
 * application time and is not operator-typable"* — and that was **factually false**.
 * `ApplyDepositToInvoiceService` stamps `$on`, a parameter, and `SettleMoveOutService` passes the
 * operator's `settlement_date` straight off an unconstrained DatePicker on the Lease resource.
 *
 * **A `system:` exemption asserting a safety property that does not hold is worse than a missing
 * entry, because the gate reports coverage.** And the gate could not have caught it: it checks the
 * registry's own declarations, and the offending field lives on a different resource under a
 * different name.
 *
 * Back-date a settlement into a closed March and 120,000 of arrears net off the deposit, the AR
 * closes, the operator sees "Saved ✓" — and the GL post is refused inside the best-effort sync job
 * that only logs. A tie-out gap the size of the settlement, with no error anywhere the operator
 * looks.
 *
 * Second defect, same act: `settleOpenAr()` ran BEFORE and OUTSIDE the settlement's transaction, so
 * a later refusal left the deposit already spent while the operator was told it had failed.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(\App\Services\Accounting\FiscalCalendar::class)->ensureYear(2026);

    $asset = makeAsset(['code' => 'MALL']);
    $this->lease = makeLease(makeUnit($asset), makeTenant(), [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'status' => 'terminated',
    ]);

    // A deposit held, and arrears for it to settle.
    DepositTransaction::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'asset_id' => $asset->id,
        'type' => 'receipt',
        'amount' => 200000,
        'transaction_date' => '2026-01-05',
        'status' => 'recorded',
    ]);

    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => '2026-02-01',
        'due_date' => '2026-02-15',
        'subtotal' => 120000, 'vat_amount' => 0, 'total' => 120000,
        'paid_amount' => 0, 'balance' => 120000,
    ]);

    $this->closedDate = CarbonImmutable::create(2026, 3, 15);
    $this->openDate = CarbonImmutable::create(2026, 6, 15);
});

/** Close the period the back-dated settlement would land in. */
function closeMarch(): void
{
    \App\Models\AccountingPeriod::forDate(\Carbon\CarbonImmutable::create(2026, 3, 15))
        ->update(['status' => 'closed']);
}

it('settles on an open date — the control', function () {
    // Without this, every refusal below could be passing because the settlement never works at all.
    $result = app(SettleMoveOutService::class)->settle($this->lease, [
        'settlement_date' => $this->openDate->toDateString(),
    ]);

    expect($result['settled_arrears']['applied'])->toBe(120000.0)
        ->and((float) $this->invoice->fresh()->balance)->toBe(0.0);
});

it('refuses a settlement back-dated into a closed period', function () {
    closeMarch();

    expect(fn () => app(SettleMoveOutService::class)->settle($this->lease, [
        'settlement_date' => $this->closedDate->toDateString(),
    ]))->toThrow(DomainException::class);
});

it('leaves the arrears untouched when the settlement is refused', function () {
    // The half-settled account. `settleOpenAr()` used to run outside the transaction, so the
    // deposit was already spent against the invoice by the time anything refused.
    closeMarch();

    try {
        app(SettleMoveOutService::class)->settle($this->lease, [
            'settlement_date' => $this->closedDate->toDateString(),
        ]);
    } catch (Throwable) {
        // expected
    }

    expect(DepositApplication::count())->toBe(0)
        ->and((float) $this->invoice->fresh()->balance)->toBe(120000.0);
});

it('refuses a direct deposit application dated into a closed period', function () {
    // The guard belongs on the service that STAMPS the date too, not only on the caller — the
    // apply path is reachable on its own from the invoice screen.
    closeMarch();

    expect(fn () => app(ApplyDepositToInvoiceService::class)->apply(
        $this->lease,
        $this->invoice,
        null,
        $this->closedDate,
    ))->toThrow(DomainException::class);
});

it('still allows a date whose period does not exist at all', function () {
    // MISSING is not CLOSED. Refusing an unopened period would make the system unusable before the
    // accountant has set up a year, and `PostingDate::assertOpen` is explicit about the difference.
    $result = app(SettleMoveOutService::class)->settle($this->lease, [
        'settlement_date' => '2031-04-10',
    ]);

    expect($result['settled_arrears']['applied'])->toBe(120000.0);
});

it('rolls the settled arrears back when the DEDUCTIONS are refused', function () {
    // The half-settled account, isolated. Nothing to do with the posting date: 120,000 of arrears
    // settle successfully out of the 200,000 deposit, leaving 80,000 — and only THEN does the
    // 150,000 of deductions fail the "cannot fund more than it holds" check.
    //
    // `settleOpenAr()` used to run before and outside the transaction, so the tenant's invoices
    // were already settled from the deposit while the operator saw an error and reasonably
    // concluded that nothing had happened.
    try {
        app(SettleMoveOutService::class)->settle($this->lease, [
            'settlement_date' => $this->openDate->toDateString(),
            'deductions' => [['description' => 'Damage to shopfront', 'amount' => 150000]],
        ]);
    } catch (Throwable) {
        // expected — the deductions exceed what is left
    }

    expect(DepositApplication::count())->toBe(0)
        ->and((float) $this->invoice->fresh()->balance)->toBe(120000.0);
});

it('registers a real guard class rather than a system exemption', function () {
    // The registry itself was the defect. Pinned so it cannot quietly revert to `system:`.
    expect(\App\Support\PostingDateGuards::guards()[DepositApplication::class])
        ->toBe(ApplyDepositToInvoiceService::class);
});
