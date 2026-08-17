<?php

use App\Models\AccountingPeriod;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\PeriodService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\PayrollService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SettleCustodyService;
use App\Support\MorphMap;
use App\Support\PostingDate;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * Regression — gap-analysis **F-93** (module 25, 🔴) and **F-89** (module 24).
 *
 * THE BUG. `SettleCustodyService` and `RecordAdvanceRepaymentService` passed a user-supplied
 * date straight through — no range check, no open-period check. Their journalizers date the
 * GL entry from it. So:
 *
 *   1. the service committed the row → outstanding dropped, operator saw "Recorded ✓"
 *   2. the journalizer built a payload dated into the CLOSED period
 *   3. JournalPostingService::assertOpenPeriodFor() threw
 *   4. ...inside the queued SyncDocumentToLedger job, which is deliberately best-effort and
 *      LOGS rather than retries
 *
 * Business state moved, the GL didn't, the operator was told it worked — the exact shape of
 * the SlaPenalty bug. Rated 🔴 for custody because for a عهدة, receipts arriving
 * after a month-end close is the NORMAL workflow, not an edge case: a custody exists
 * *because* the money is spent before the paperwork lands.
 *
 * The nastier variant: a settlement dated BEFORE its custody was granted put the Cr Custodies
 * in an earlier period than the Dr grant — **a credit balance on an asset account** in that
 * month's trial balance. Nothing forbade it.
 *
 * Both services now refuse via {@see PostingDate}, in the SERVICE — the form's
 * minDate/maxDate is UX, and the API and console never see it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'PDG']);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'PDG-1', 'name' => 'Ahmed Mahmoud',
        'hire_date' => now()->startOfYear()->toDateString(),
        'base_salary' => 8000, 'payment_method' => 'cash',
    ]);
});

/**
 * Close the accounting period covering $date, exactly the way an operator's month-end close
 * does — sync first, then close. The close gate refuses a period holding an out-of-sync
 * document ("Run 'Post to GL now' … then close"), which is itself correct and is why the
 * sweep runs here first.
 */
function closePeriodFor(string $date): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $period = AccountingPeriod::forDate(Carbon::parse($date));
    app(PeriodService::class)->closePeriod($period);
}

/** Granted through the real service, dated at the start of this month. */
function pdgCustody(float $amount = 10000): Custody
{
    return app(GrantCustodyService::class)->grant(test()->employee, [
        'amount' => $amount,
        'custody_date' => now()->startOfMonth()->toDateString(),
        'paid_from' => 'cash',
        'purpose' => 'Site petty cash',
    ]);
}

/** Granted through the real service, dated at the start of this month. */
function pdgAdvance(float $amount = 10000): EmployeeAdvance
{
    return app(GrantEmployeeAdvanceService::class)->grant(test()->employee, [
        'type' => 'advance',
        'amount' => $amount,
        'advance_date' => now()->startOfMonth()->toDateString(),
        'paid_from' => 'cash',
    ]);
}

/* ---- F-93 · custody --------------------------------------------------------- */

it('refuses a custody settlement dated into a closed period', function () {
    $custody = pdgCustody();
    $inClosedMonth = now()->startOfMonth()->addDays(5)->toDateString();

    // The month-end close happens; THEN the receipts arrive. This is the normal sequence.
    closePeriodFor($inClosedMonth);

    expect(fn () => app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 5000, 'category' => 'other',
        'transaction_date' => $inClosedMonth,
    ]))->toThrow(DomainException::class);

    // The whole point: business state must NOT move when the GL can't follow.
    expect($custody->fresh()->transactions()->count())->toBe(0)
        ->and((float) $custody->fresh()->outstanding())->toBe(10000.0);
});

it('refuses a custody settlement dated before the custody was granted', function () {
    // The negative-asset variant: Cr Custodies would land in a period before the Dr grant.
    $custody = pdgCustody();

    expect(fn () => app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 5000, 'category' => 'other',
        'transaction_date' => now()->startOfMonth()->subDays(3)->toDateString(),
    ]))->toThrow(DomainException::class);

    expect($custody->fresh()->transactions()->count())->toBe(0);
});

it('refuses a custody settlement dated in the future', function () {
    $custody = pdgCustody();

    expect(fn () => app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 5000, 'category' => 'other',
        'transaction_date' => now()->addDays(10)->toDateString(),
    ]))->toThrow(DomainException::class);

    expect($custody->fresh()->transactions()->count())->toBe(0);
});

it('still accepts a settlement dated in an open period', function () {
    // The guard must refuse the bad case WITHOUT breaking the real workflow.
    $custody = pdgCustody();

    $txn = app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 5000, 'category' => 'other',
        'transaction_date' => now()->toDateString(),
    ]);

    expect($txn->exists)->toBeTrue()
        ->and((float) $custody->fresh()->outstanding())->toBe(5000.0);

    // And it genuinely reaches the ledger through the real path.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(JournalEntry::where('source_type', MorphMap::alias(CustodyTransaction::class))
        ->where('status', 'posted')->exists())->toBeTrue();
});

/* ---- F-89 · employee advance ------------------------------------------------ */

it('refuses an advance repayment dated into a closed period', function () {
    $advance = pdgAdvance();

    $inClosedMonth = now()->startOfMonth()->addDays(5)->toDateString();
    closePeriodFor($inClosedMonth);

    expect(fn () => app(RecordAdvanceRepaymentService::class)->record($advance, [
        'amount' => 4000, 'repaid_on' => $inClosedMonth, 'method' => 'cash',
    ]))->toThrow(DomainException::class);

    expect($advance->fresh()->repayments()->count())->toBe(0)
        ->and((float) $advance->fresh()->outstanding())->toBe(10000.0);
});

it('still accepts an advance repayment dated in an open period', function () {
    $advance = pdgAdvance();

    app(RecordAdvanceRepaymentService::class)->record($advance, [
        'amount' => 4000, 'repaid_on' => now()->toDateString(), 'method' => 'cash',
    ]);

    expect((float) $advance->fresh()->outstanding())->toBe(6000.0);
});

/* ---- the GRANT side — the half the F-93/F-89 fix missed ------------------- */

/**
 * F-93 and F-89 guarded the money going OUT of these documents (settlement,
 * repayment) and left the money going IN unguarded — the same silent divergence
 * on the sibling half of the same document. Found in the module 24/25
 * gap-analysis sweep (2026-07-29).
 */
it('refuses a custody GRANTED into a closed period', function () {
    $inClosedMonth = now()->startOfMonth()->addDays(5)->toDateString();
    closePeriodFor($inClosedMonth);

    expect(fn () => app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000,
        'custody_date' => $inClosedMonth,
        'paid_from' => 'cash',
    ]))->toThrow(DomainException::class);

    expect(Custody::count())->toBe(0, 'A custody was recorded that the books can never carry.');
});

it('leaves no unsettleable custody behind', function () {
    // Why the grant side matters as much as the settlement side: an unguarded grant
    // creates a عهدة the custodian is on the hook for, with no Dr Custodies / Cr Cash
    // behind it — and the settlement guard then refuses every settlement (it may not
    // predate its grant), so the custody is stuck: recorded, unbacked, unsettleable.
    $inClosedMonth = now()->startOfMonth()->addDays(5)->toDateString();
    closePeriodFor($inClosedMonth);

    try {
        app(GrantCustodyService::class)->grant($this->employee, [
            'amount' => 10000, 'custody_date' => $inClosedMonth, 'paid_from' => 'cash',
        ]);
    } catch (DomainException) {
        // expected
    }

    expect(Custody::count())->toBe(0);
});

it('still grants a custody in an open period', function () {
    $custody = app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000,
        'custody_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ]);

    expect($custody->exists)->toBeTrue();
});

it('refuses an advance GRANTED into a closed period', function () {
    $inClosedMonth = now()->startOfMonth()->addDays(5)->toDateString();
    closePeriodFor($inClosedMonth);

    expect(fn () => app(GrantEmployeeAdvanceService::class)->grant($this->employee, [
        'amount' => 5000,
        'advance_date' => $inClosedMonth,
        'paid_from' => 'cash',
    ]))->toThrow(DomainException::class);

    // Otherwise the employee carries an outstanding balance with no Dr Employee
    // Advances behind it, and the repayments that later relieve that receivable
    // credit an account the grant never debited.
    expect(EmployeeAdvance::count())->toBe(0);
});

it('still grants an advance in an open period', function () {
    $advance = app(GrantEmployeeAdvanceService::class)->grant($this->employee, [
        'amount' => 5000,
        'advance_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ]);

    expect($advance->exists)->toBeTrue();
});

it('refuses APPROVING a payroll run whose month has closed', function () {
    // A run can sit in draft across a month-end close. Approval is the moment it
    // becomes GL-postable, so the check belongs there and not only at drafting:
    // approving relieves every advance installment in the run and marks salaries
    // paid, while Dr Salaries / Cr Cash silently fails in the best-effort job.
    $month = now()->startOfMonth();

    $payroll = Payroll::create([
        'asset_id' => $this->asset->id,
        'period_month' => $month->toDateString(),
        'status' => 'draft',
        'paid_from' => 'cash',
        'gross_salaries' => 8000,
        'net_paid' => 8000,
    ]);

    closePeriodFor($month->toDateString());

    expect(fn () => app(PayrollService::class)->approve($payroll))
        ->toThrow(DomainException::class);

    expect($payroll->fresh()->status)->toBe('draft');
});

it('still approves a payroll run in an open month', function () {
    $payroll = Payroll::create([
        'asset_id' => $this->asset->id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'status' => 'draft',
        'paid_from' => 'cash',
        'gross_salaries' => 8000,
        'net_paid' => 8000,
    ]);

    expect(app(PayrollService::class)->approve($payroll)->status)->toBe('approved');
});
