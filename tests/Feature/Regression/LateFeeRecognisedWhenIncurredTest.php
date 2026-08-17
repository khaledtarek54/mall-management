<?php

use App\Models\AccountingPeriod;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\LateFeeService;
use App\Services\MonthlyBillingService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A late fee is April's revenue, not January's.
 *
 * The fee used to be appended as a line item to the OVERDUE invoice. `InvoiceJournalizer` dates its
 * entry from the invoice's `issue_date`, so a penalty charged in April on a January invoice was
 * recognised as **January revenue** — restating a month already closed, already reported to the
 * owner and possibly already filed, from an 04:00 cron with nobody watching. It also restated an
 * issued document: the tenant's copy of that invoice no longer matched ours.
 *
 * The fee is now its own invoice dated when it was incurred, which is what CAM true-ups,
 * percentage-rent overages and violation fines already do.
 *
 * Two consequences that had to move with it, both of which have bitten this codebase before:
 *
 *  - **`late_fee` joins the already-billed probe exclusion.** A standalone invoice dated into the
 *    current month otherwise reads as "this lease is already billed" and the recurring run silently
 *    skips its rent — the exact revenue leak fixed one at a time for `percentage_rent`,
 *    `utility`, `violation_fine` and `nsf_fee`.
 *  - **The closed period now refuses the fee** instead of quietly posting into it. `Invoice`
 *    carries the posting-date guard, so the batch logs the failure and moves on rather than
 *    booking a month that is sealed.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 5;
    $settings->late_fee_grace_days = 7;
    $settings->late_fee_minimum = 0;

    $this->lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'status' => 'active',
    ]);

    // January's rent, unpaid.
    $this->overdue = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-10',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
    ]);

    $this->april = CarbonImmutable::create(2026, 4, 15);
});

it('dates the fee when it was incurred, not when the rent was', function () {
    app(LateFeeService::class)->applyTo($this->overdue, $this->april);

    $fee = $this->overdue->fresh()->lateFeeInvoice;

    expect($fee)->not->toBeNull()
        ->and($fee->issue_date->toDateString())->toBe('2026-04-15')
        ->and((float) $fee->total)->toBe(500.0);
});

it('recognises the fee in April in the LEDGER', function () {
    // The assertion that matters. Before this the entry was January's, because the line sat on
    // January's invoice and the journalizer dates from `issue_date`.
    app(LateFeeService::class)->applyTo($this->overdue, $this->april);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $fee = $this->overdue->fresh()->lateFeeInvoice;

    $entryDate = JournalEntry::query()
        ->where('source_type', $fee->getMorphClass())
        ->where('source_id', $fee->id)
        ->where('status', 'posted')
        ->value('entry_date');

    expect(CarbonImmutable::parse($entryDate)->toDateString())->toBe('2026-04-15');
});

it('leaves the overdue invoice exactly as the tenant received it', function () {
    app(LateFeeService::class)->applyTo($this->overdue, $this->april);

    $after = $this->overdue->fresh();

    // An issued document is not restated. Its own GL entry is untouched too, which is why January
    // stays closed and reconciled.
    expect((float) $after->subtotal)->toBe(10000.0)
        ->and((float) $after->total)->toBe(10000.0)
        ->and((float) $after->balance)->toBe(10000.0)
        ->and($after->items()->where('type', 'late_fee')->exists())->toBeFalse();
});

it('does not suppress that month\'s rent billing', function () {
    // The probe-exclusion trap, fixed in the SAME change that creates the standalone invoice rather
    // than after someone lost a month's rent to it.
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base rent',
        'type' => 'base_rent',
        'amount' => 10000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    app(LateFeeService::class)->applyTo($this->overdue, $this->april);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($this->lease->fresh(), CarbonImmutable::create(2026, 4, 1));

    expect($result['status'])->toBe('created')
        ->and((float) $result['invoice']->total)->toBe(10000.0);
});

it('refuses to charge a fee into a closed period', function () {
    // The overdue invoice's own month is closed and stays that way — but so is April in this case,
    // and the fee posts in April, so the guard on `Invoice` refuses it. Previously the fee was a
    // line on a January invoice and never went near a posting-date check at all.
    AccountingPeriod::forDate($this->april)->update(['status' => 'closed']);

    expect(fn () => app(LateFeeService::class)->applyTo($this->overdue, $this->april))
        ->toThrow(DomainException::class);

    expect(Invoice::count())->toBe(1);
});

it('still charges when only the ORIGINAL invoice\'s month is closed', function () {
    // The paired control, and the whole point of the change: January being sealed must not stop
    // April's penalty. Under the old behaviour this was the case that silently corrupted the books.
    AccountingPeriod::forDate(CarbonImmutable::create(2026, 1, 15))->update(['status' => 'closed']);

    expect(app(LateFeeService::class)->applyTo($this->overdue, $this->april))->toBeTrue();
});
