<?php

use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * GL integrity hardening — Phase 4: the close gate prevents the closed-period reversal trap
 * from being created (don't close a period while a document in it still needs re-posting), and
 * the `--deep` reconcile verifies every posting document's entry matches its current state
 * (catching drift the AR/AP control-account tie-out can't see).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

function closeGateInvoice(): Invoice
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);

    return $invoice;
}

it('refuses to close a period with a document whose ledger entry is out of sync', function () {
    $invoice = closeGateInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful(); // entry posted

    // Edit the line item (money-neutral) WITHOUT re-syncing — the posted entry is now stale.
    $invoice->items()->first()->update(['type' => 'service_charge']);

    $period = AccountingPeriod::forDate(now());
    expect(fn () => app(PeriodService::class)->closePeriod($period))->toThrow(\DomainException::class);
    expect($period->fresh()->status)->toBe('open'); // still open — the close was refused
});

it('closes a period once every document is in sync', function () {
    closeGateInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $period = AccountingPeriod::forDate(now());
    app(PeriodService::class)->closePeriod($period);

    expect($period->fresh()->status)->toBe('closed');
});

it('refuses to close a period with an issued-but-NEVER-posted document dated in it', function () {
    // An issued invoice dated in the current period with NO journal entry yet (real-time is off
    // in tests, and we never run the sweep) — the exact F4 gap the close gate initially missed.
    $invoice = closeGateInvoice();
    expect(\App\Models\JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->count())->toBe(0); // never posted

    $period = AccountingPeriod::forDate(now());
    expect(fn () => app(PeriodService::class)->closePeriod($period))->toThrow(\DomainException::class);
    expect($period->fresh()->status)->toBe('open'); // refused — closing would strand its post

    // Once it's posted, the close succeeds.
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    app(PeriodService::class)->closePeriod($period->fresh());
    expect($period->fresh()->status)->toBe('closed');
});

it('the --deep reconcile catches a drifted revenue split the AR tie-out misses', function () {
    $invoice = closeGateInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // Re-type the item (rent → service, same amount): the GL revenue account drifts but AR
    // (invoice total) is unchanged — so the AR/AP tie-out still passes, only --deep catches it.
    $invoice->items()->first()->update(['type' => 'service_charge']);

    $this->artisan('billing:reconcile')->assertSuccessful();    // shallow: AR still ties out
    $this->artisan('billing:reconcile --deep')->assertFailed();  // deep: the revenue split drifted

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful(); // re-sync fixes it
    $this->artisan('billing:reconcile --deep')->assertSuccessful();
});
