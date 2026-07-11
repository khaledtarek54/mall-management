<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PeriodService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

/**
 * Accounting-periods EDGE scenarios — the close gate as a state machine.
 *
 * The happy-path flow (open→drift→refuse→resync→close, plus reconcile) lives in
 * GlCloseAndReconcileScenarioTest. This file adds the missing edge classes:
 *   - state-transition: posting/voiding INTO a closed period is refused; reopen restores it
 *   - boundary: an entry dated on the exact first/last day of a period still lands in it
 *   - scoping: closing one period doesn't touch a sibling period in the same year
 *   - reopen semantics on a fiscal-year round-trip
 *
 * We post plain manual balanced entries (Dr cash / Cr rent-revenue) so the tests exercise
 * the period gate directly, without the invoice→journalizer drift machinery.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15, 12));
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A balanced manual entry (Dr cash / Cr rent-revenue) dated on $date. */
function periodEntry(string|Carbon $date, float $amount = 1000): JournalEntry
{
    $resolver = app(AccountResolver::class);

    return app(JournalPostingService::class)->post([
        'entry_date' => $date instanceof Carbon ? $date->toDateString() : $date,
        'description_en' => 'Manual test entry',
        'is_manual' => true,
        'lines' => [
            ['ledger_account_id' => $resolver->id('cash'), 'debit' => $amount, 'credit' => 0],
            ['ledger_account_id' => $resolver->id('rent_revenue'), 'debit' => 0, 'credit' => $amount],
        ],
    ]);
}

it('refuses to post a journal entry whose date falls in a CLOSED period', function () {
    $june = AccountingPeriod::forDate(Carbon::create(2026, 6, 15));
    app(PeriodService::class)->closePeriod($june);
    expect($june->fresh()->status)->toBe('closed');

    expect(fn () => periodEntry('2026-06-20'))
        ->toThrow(\DomainException::class);

    // Nothing landed on the books for June.
    expect(JournalEntry::whereDate('entry_date', '2026-06-20')->count())->toBe(0);
});

it('refuses to void a posted entry that lives in a CLOSED period once the current period is also closed', function () {
    // Post in June, then close BOTH June and today's period (both are June here).
    $entry = periodEntry('2026-06-10');
    expect($entry->status)->toBe('posted');

    $june = AccountingPeriod::forDate(Carbon::create(2026, 6, 10));
    app(PeriodService::class)->closePeriod($june);

    // reversalPeriod tries the entry's own period then now() — both June, both closed → refuse.
    expect(fn () => app(JournalPostingService::class)->void($entry->fresh(), 'test'))
        ->toThrow(\DomainException::class);

    expect($entry->fresh()->status)->toBe('posted'); // still on the books, untouched
});

it('lets a closed period be reopened and posted into again (reopen semantics)', function () {
    $june = AccountingPeriod::forDate(Carbon::create(2026, 6, 15));
    app(PeriodService::class)->closePeriod($june);
    expect(fn () => periodEntry('2026-06-18'))->toThrow(\DomainException::class);

    // Reopen restores posting.
    $reopened = app(PeriodService::class)->reopenPeriod($june->fresh());
    expect($reopened->status)->toBe('open')
        ->and($june->fresh()->isOpen())->toBeTrue();

    $entry = periodEntry('2026-06-18');
    expect($entry->status)->toBe('posted')
        ->and($entry->accounting_period_id)->toBe($june->id);
});

it('posts an entry dated on the exact first and last day of a period into that period (boundary)', function () {
    $march = AccountingPeriod::where('fiscal_year_id', FiscalYear::where('year', 2026)->value('id'))
        ->where('period_no', 3)
        ->firstOrFail();

    // Reopen not needed — March is open. Post on the first and last calendar day.
    $first = periodEntry($march->starts_on->toDateString());
    $last = periodEntry($march->ends_on->toDateString());

    expect($first->accounting_period_id)->toBe($march->id)
        ->and($last->accounting_period_id)->toBe($march->id)
        ->and($march->starts_on->toDateString())->toBe('2026-03-01')
        ->and($march->ends_on->toDateString())->toBe('2026-03-31');
});

it('closing one period leaves its sibling periods in the same year open (period scoping)', function () {
    $yearId = FiscalYear::where('year', 2026)->value('id');
    $june = AccountingPeriod::where('fiscal_year_id', $yearId)->where('period_no', 6)->firstOrFail();
    $july = AccountingPeriod::where('fiscal_year_id', $yearId)->where('period_no', 7)->firstOrFail();

    app(PeriodService::class)->closePeriod($june);

    expect($june->fresh()->status)->toBe('closed')
        ->and($july->fresh()->status)->toBe('open');

    // Posting into the still-open sibling works; posting into the closed one does not.
    expect(periodEntry('2026-07-05')->status)->toBe('posted');
    expect(fn () => periodEntry('2026-06-05'))->toThrow(\DomainException::class);
});

it('reopens a whole fiscal year and its periods after a clean close (year reopen semantics)', function () {
    $year = FiscalYear::where('year', 2026)->firstOrFail();

    $closed = app(PeriodService::class)->closeFiscalYear($year);
    expect($closed->status)->toBe('closed')
        ->and($closed->periods()->where('status', 'open')->count())->toBe(0);

    // A closed year refuses fresh posts anywhere inside it.
    expect(fn () => periodEntry('2026-06-15'))->toThrow(\DomainException::class);

    $reopened = app(PeriodService::class)->reopenFiscalYear($year->fresh());
    expect($reopened->status)->toBe('open')
        ->and($reopened->periods()->where('status', 'closed')->count())->toBe(0);

    // …and posting works again.
    expect(periodEntry('2026-06-15')->status)->toBe('posted');
});
