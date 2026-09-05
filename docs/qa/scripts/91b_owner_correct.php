<?php

require __DIR__.'/boot.php';
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\OwnerStatementRun;
use App\Models\User;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerReportService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$admin = User::where('email', 'admin@mall.test')->firstOrFail();
$acct = fn ($r) => app(AccountResolver::class)->id($r);
// June, not July: DemoSeeder now finalises a July statement for this asset, and the service
// correctly refuses regenerating over a finalised run — revise is the path for THAT one.
$period = AccountingPeriod::whereDate('starts_on', '<=', '2026-06-01')->whereDate('ends_on', '>=', '2026-06-30')->firstOrFail();

qa_section('OWNER STATEMENTS — the run ties to the income statement AND to its own children');
$run = app(GenerateOwnerStatementRunService::class)->generate($asset, $period);
printf("  run #%d: revenue=%s expense=%s NOI=%s distributable=%s\n", $run->id,
    number_format((float) $run->total_revenue, 2), number_format((float) $run->total_expense, 2),
    number_format((float) $run->net_operating_income, 2), number_format((float) $run->net_distributable, 2));
foreach ($run->statements as $s) {
    printf("    owner #%d %s%%  share_revenue=%s share_expense=%s owner_share=%s\n", $s->user_id,
        $s->ownership_percentage, number_format((float) $s->share_revenue, 2),
        number_format((float) $s->share_expense, 2), number_format((float) $s->owner_share, 2));
}
qa_eq('NOI = revenue − expense', round((float) $run->total_revenue - (float) $run->total_expense, 2),
    round((float) $run->net_operating_income, 2), 0.02);
qa_eq('Σ owner shares = net distributable', round((float) $run->statements->sum('owner_share'), 2),
    round((float) $run->net_distributable, 2), 0.02);
qa_eq('Σ share_revenue = the run revenue', round((float) $run->total_revenue, 2),
    round((float) $run->statements->sum('share_revenue'), 2), 0.05);

$is = app(LedgerReportService::class)->incomeStatement([$asset->id],
    CarbonImmutable::parse($period->starts_on), CarbonImmutable::parse($period->ends_on));
printf("  income statement for the same period: revenue=%s expense=%s net=%s\n",
    number_format((float) $is['total_revenue'], 2), number_format((float) $is['total_expense'], 2),
    number_format((float) $is['net_profit'], 2));
qa_eq('the run revenue equals the income statement revenue', round((float) $is['total_revenue'], 2),
    round((float) $run->total_revenue, 2), 0.05);
qa_eq('the run expense equals the income statement expense', round((float) $is['total_expense'], 2),
    round((float) $run->total_expense, 2), 0.05);
qa_eq('net distributable equals the period net profit', round((float) $is['net_profit'], 2),
    round((float) $run->net_distributable, 2), 0.05);

qa_section('…and the posted liability equals the net distributable');
$fin = app(FinaliseOwnerStatementRunService::class)->finalise($run->fresh(), $admin, '2026-08-01');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$e = JournalEntry::where('source_type', $fin->getMorphClass())->where('source_id', $fin->id)->where('status', 'posted')->first();
qa_dump_entry($e, 'owner statement run');
$due = (float) $e?->lines->firstWhere('ledger_account_id', $acct('due_to_owner'))?->credit;
qa_eq('Cr Due to Owner = net_distributable', round((float) $fin->fresh()->net_distributable, 2), round($due, 2), 0.05);
qa_eq('…and = Σ the owners statements', round((float) $fin->fresh()->statements->sum('owner_share'), 2), round($due, 2), 0.05);

qa_section('REVISE supersedes and the sweep voids the old entry');
$rev = app(ReviseOwnerStatementRunService::class)->revise($fin->fresh(), $admin, '2026-08-02');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
printf("  original #%d status=%s · revision #%d v%d status=%s\n", $fin->id, $fin->fresh()->status,
    $rev->id, $rev->version, $rev->status);
qa_ok('the original is superseded, not deleted', OwnerStatementRun::whereKey($fin->id)->exists());
$oldPosted = JournalEntry::where('source_type', $fin->getMorphClass())->where('source_id', $fin->id)->where('status', 'posted')->count();
qa_eq('the superseded run has no live posted entry', 0, $oldPosted);
qa_assert_tb('after revise');

// F-13 is FIXED (2026-08-19): `finalise()` returns the already-finalised run instead of throwing.
// This block still asserted the pre-fix behaviour, so the harness reported a red for the very
// change that closed the finding — which is how a suite trains people to ignore it.
qa_section('F-13 FIXED — finalise() is idempotent');
$again = app(FinaliseOwnerStatementRunService::class)->finalise(
    OwnerStatementRun::whereKey($rev->id)->firstOrFail(), $admin, '2026-08-02');
qa_eq('a second finalise returns the SAME run rather than throwing', $rev->id, $again->id);
qa_eq('…but nothing is double-posted', 1,
    JournalEntry::where('source_type', $rev->getMorphClass())->where('source_id', $rev->id)->where('status', 'posted')->count());

qa_section('FINAL TIE-OUT');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));
qa_summary();
