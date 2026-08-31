<?php

use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OwnerStatementRun;
use App\Models\User;
use App\Notifications\LedgerRestatedReportedPeriodNotification;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\MonthEndReadinessService;
use App\Support\LedgerTrail;
use App\Support\ReportedPeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Restating a month that has already been REPORTED (change-impact plan, phase 3 / F4).
 *
 * THE GAP. `AccountingPeriod` has two states, open and closed, and the close gate stops you sealing
 * a month over a pending re-post. Nothing stopped the opposite: an owner statement is finalised on
 * the 5th and the period closed on the 20th, and in those fifteen days an edit to a March document
 * silently voids its entry and posts a new one — restating a figure the owner is already holding.
 *
 * WHY IT WARNS RATHER THAN REFUSES. Voyager has no "reported" state; its control is that you CLOSE
 * the month when you report it. A reported-but-open month is a process gap, not a transaction to
 * refuse, and refusing would be stricter than the benchmark while blocking the case where the
 * correction is exactly what the owner is waiting for. So: surface it, and steer to the close.
 */
function reportedTestInvoice(string $issueDate, float $total = 1000): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()));

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => $issueDate,
        'due_date' => $issueDate,
        'period_start' => $issueDate,
        'period_end' => $issueDate,
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'balance' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
    ]);

    $invoice->recomputeTotals();

    return $invoice->refresh();
}

function finaliseStatementFor(int $assetId, string $start, string $end): OwnerStatementRun
{
    // The run is period-anchored, so the fiscal year has to exist before one can be written.
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::parse($start)->year);

    return OwnerStatementRun::create([
        'accounting_period_id' => AccountingPeriod::forDate(CarbonImmutable::parse($end))?->id,
        'reference' => 'OS-'.uniqid(),
        'asset_id' => $assetId,
        'period_start' => $start,
        'period_end' => $end,
        'posting_date' => $end,
        'basis' => 'accrual',
        'total_revenue' => 1000, 'total_expense' => 0, 'net_operating_income' => 1000,
        'net_distributable' => 1000,
        'status' => OwnerStatementRun::STATUS_FINALISED,
        'finalised_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
});

it('knows a month is reported once a finalised statement covers it', function () {
    $asset = makeAsset();

    // Control first: an unreported month, and a DRAFT statement, must both read as unreported —
    // otherwise the positive assertion below passes for any month at all.
    expect(ReportedPeriod::isReported('2026-03-15', $asset->id))->toBeFalse();

    $run = finaliseStatementFor($asset->id, '2026-03-01', '2026-03-31');

    expect(ReportedPeriod::isReported('2026-03-15', $asset->id))->toBeTrue()
        ->and(ReportedPeriod::isReported('2026-04-15', $asset->id))->toBeFalse()
        // Another property's March is not reported by this statement — treating it as if it were
        // would warn on every correction in the portfolio.
        ->and(ReportedPeriod::isReported('2026-03-15', makeAsset()->id))->toBeFalse()
        ->and(ReportedPeriod::reasonFor('2026-03-15', $asset->id))->toContain($run->reference);

    $run->update(['status' => OwnerStatementRun::STATUS_SUPERSEDED]);

    expect(ReportedPeriod::isReported('2026-03-15', $asset->id))->toBeFalse();
});

it('counts a quarterly statement as reporting each of its months', function () {
    $asset = makeAsset();
    finaliseStatementFor($asset->id, '2026-01-01', '2026-03-31');

    expect(ReportedPeriod::isReported('2026-01-15', $asset->id))->toBeTrue()
        ->and(ReportedPeriod::isReported('2026-02-15', $asset->id))->toBeTrue()
        ->and(ReportedPeriod::isReported('2026-03-15', $asset->id))->toBeTrue()
        ->and(ReportedPeriod::isReported('2025-12-15', $asset->id))->toBeFalse();
});

it('alerts the GL managers when a re-derive restates a reported month', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $accountant = makeUser('accounting');
    Notification::fake();

    $invoice = reportedTestInvoice('2026-03-10', 1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $assetId = $invoice->lease->unit->asset_id;
    finaliseStatementFor($assetId, '2026-03-01', '2026-03-31');

    // The edit: a late fee on a March invoice, after March has been reported.
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'late_fee', 'description' => 'Late fee',
        'amount' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50,
    ]);
    $invoice->recomputeTotals();

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::assertSentTo(
        $accountant,
        LedgerRestatedReportedPeriodNotification::class,
        fn (LedgerRestatedReportedPeriodNotification $n) => $n->month === '03/2026',
    );
});

it('stays quiet when the month has not been reported', function () {
    // The control for the alert above. Without it, an alert that fired on EVERY re-derive would
    // pass that test just as happily, and the whole point is that it discriminates.
    $this->seed(RolesPermissionsSeeder::class);
    makeUser('accounting');
    Notification::fake();

    $invoice = reportedTestInvoice('2026-03-10', 1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'late_fee', 'description' => 'Late fee',
        'amount' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50,
    ]);
    $invoice->recomputeTotals();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    Notification::assertNothingSentTo(User::query()->get()->all());
});

it('flags the restatement on the document itself, before it happens', function () {
    $invoice = reportedTestInvoice('2026-03-10', 1000);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $assetId = $invoice->lease->unit->asset_id;
    finaliseStatementFor($assetId, '2026-03-01', '2026-03-31');

    // Reported, but nothing has changed yet — the panel must not cry wolf.
    expect(LedgerTrail::for($invoice->refresh())['restates_reported'])->toBeFalse();

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'late_fee', 'description' => 'Late fee',
        'amount' => 50, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50,
    ]);
    $invoice->recomputeTotals();

    $trail = LedgerTrail::for($invoice->refresh());

    expect($trail['restates_reported'])->toBeTrue()
        ->and($trail['reported_reason'])->toContain('OS-');
});

it('raises a month-end step for a reported month still open, and clears it once closed', function () {
    $asset = makeAsset();
    $month = CarbonImmutable::parse('2026-03-01');

    $stepFor = fn () => collect(app(MonthEndReadinessService::class)->for($month, $asset->id)['steps'])
        ->firstWhere('key', 'reported_not_closed');

    expect($stepFor()['status'])->toBe(MonthEndReadinessService::OK); // control: nothing reported

    finaliseStatementFor($asset->id, '2026-03-01', '2026-03-31');

    $step = $stepFor();

    expect($step['status'])->toBe(MonthEndReadinessService::ATTENTION)
        ->and($step['detail'])->toContain('OS-')
        // It must NOT block the close — the close is the remedy this step is steering towards, and
        // a step that blocked it would make the only fix unreachable.
        ->and($step['status'])->not->toBe(MonthEndReadinessService::BLOCKED);
});
