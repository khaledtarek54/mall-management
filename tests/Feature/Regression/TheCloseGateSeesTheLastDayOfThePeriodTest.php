<?php

use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **The close gate sees a document dated on the period's LAST DAY** — SW-141.
 *
 * Part (b) of `PeriodService::assertPeriodsReconciled()` is the gate's answer to a document that
 * has NEVER posted — real-time sync off, the queue backlogged, a best-effort sync that failed once.
 * Close the period underneath it and its post is stranded for good, because posting into a closed
 * period throws: the vendor keeps the deduction on their bill and the ledger never records it.
 *
 * It scanned `whereBetween($dateColumn, [$period->starts_on, $period->ends_on])`. Both are `date`
 * casts, so the Carbon bindings compile to `between '2026-08-01 00:00:00' and '2026-08-31 00:00:00'`
 * — measured on that exact query at HEAD (2026-09-04). Every column in
 * `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` is a `date` except ONE: `sla_penalties.applied_at` is a
 * `dateTime`. So a penalty applied at any time of day on the LAST day of the period sat past the
 * upper bound and the gate never saw it.
 *
 * The refusals below are paired with two controls that must SUCCEED — a clean period still closes,
 * and a penalty in the NEXT period does not block this one — because a window widened to everything
 * would satisfy the refusals on its own.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    // Periods by ROLE rather than by month name, so the fixture is right whatever
    // `AccountingSettings::fiscal_year_start_month` says and whenever the suite is run.
    $periods = app(FiscalCalendar::class)->ensureYear((int) now()->year)
        ->periods()->orderBy('period_no')->get();

    $this->earlier = $periods[0];   // where the bill lives — a different period, so it can never
    $this->period = $periods[1];    // …be the reason this close is refused
    $this->later = $periods[2];

    $this->asset = makeAsset(['code' => 'CGT']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'status' => 'active']);

    $this->bill = VendorBill::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => $this->earlier->starts_on->copy()->addDays(4)->toDateString(),
        'due_date' => $this->earlier->ends_on->toDateString(),
        'subtotal' => 5000,
        'vat_amount' => 0,
    ]);
    $this->bill->recompute();

    // A closure rather than a file-scope helper: two test files declaring one helper name is a fatal
    // redeclaration that exits the whole suite 255 with no output on either stream.
    $this->penaltyAppliedAt = function (string $appliedAt): SlaPenalty {
        return SlaPenalty::create([
            'facility_work_order_id' => correctiveOrder()->id,
            'asset_id' => $this->asset->id,
            'vendor_id' => $this->vendor->id,
            'basis' => SlaPenalty::BASIS_FLAT,
            'rate' => 500,
            'hours_over_sla' => 6,
            'amount' => 500,
            'status' => SlaPenalty::STATUS_APPLIED,
            'vendor_bill_id' => $this->bill->id,
            'finalised_at' => $appliedAt,
            'applied_at' => $appliedAt,
        ]);
    };
});

it('refuses to close a period holding a penalty applied on its last day', function () {
    $penalty = ($this->penaltyAppliedAt)($this->period->ends_on->copy()->setTime(9, 15)->toDateTimeString());

    // The premise the whole defect rests on: this column carries a TIME, and the period bound is a
    // date. Without both halves the test would pass on a fixture that was never in the blind spot.
    expect($penalty->applied_at->format('H:i'))->toBe('09:15')
        ->and($penalty->applied_at->toDateString())->toBe($this->period->ends_on->toDateString());

    expect(fn () => app(PeriodService::class)->closePeriod($this->period))
        ->toThrow(DomainException::class);

    expect($this->period->fresh()->status)->toBe('open');
});

it('still sees one applied at the first moment of the period', function () {
    // The other bound, pinned because the fix rewrote both: a half-open window compared as date
    // STRINGS must not lose the first day the way a datetime lower bound does on SQLite.
    ($this->penaltyAppliedAt)($this->period->starts_on->copy()->startOfDay()->toDateTimeString());

    expect(fn () => app(PeriodService::class)->closePeriod($this->period))
        ->toThrow(DomainException::class);

    expect($this->period->fresh()->status)->toBe('open');
});

it('closes a period whose documents are all accounted for', function () {
    // The control that keeps the refusals honest: the bill sits in an earlier period, nothing is
    // dated in this one, and the close goes through.
    app(PeriodService::class)->closePeriod($this->period);

    expect($this->period->fresh()->status)->toBe('closed');
});

it('does not reach into the period after it', function () {
    // The mutation guard. A window widened to "everything" would satisfy both refusals above while
    // making every close impossible for ever.
    ($this->penaltyAppliedAt)($this->later->starts_on->copy()->setTime(9, 15)->toDateTimeString());

    app(PeriodService::class)->closePeriod($this->period);

    expect($this->period->fresh()->status)->toBe('closed');
});
