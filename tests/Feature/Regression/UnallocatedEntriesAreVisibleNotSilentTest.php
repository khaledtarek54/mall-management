<?php

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\Asset;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * EG-27, the half that is unambiguous — a journal entry filed against no property was invisible in
 * every financial statement, and nothing said so (finding S-3).
 *
 * `aggregate()` and `accountLedger()` both scope with `whereIn('je.asset_id', $ids)`, and `whereIn`
 * never matches NULL. The year-end close already knew better: `plByAssetAndAccount()` buckets those
 * rows under `asset_id => null` precisely *"so no P&L is ever stranded"*. The close and the reports
 * disagreed, and the reports were the ones an operator reads.
 *
 * **They are surfaced, not folded in, and that is the decision the operator took.** A null
 * `asset_id` on a money document is portfolio-level overhead visible from every mall
 * (`#[PropertyOwned(portfolioRowsWhenNull: true)]`), so absorbing it into each property's statement
 * would show one operator-wide insurance bill in full on all three malls and none of their figures
 * would be right. `atriom:audit-property-dimension` is what finds and fixes it; this makes the
 * statement admit it exists.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $this->mall = makeAsset(['code' => 'MALL-U1']);
    $this->service = app(LedgerReportService::class);

    $this->debit = LedgerAccount::where('type', 'expense')->where('is_postable', true)->firstOrFail();
    $this->credit = LedgerAccount::where('type', 'liability')->where('is_postable', true)->firstOrFail();
});

/**
 * A posted entry for `$asset` (null = filed against no property).
 *
 * `$debit` and `$closing` exist for the two cases where the notice must count a NARROWER population
 * than "every unallocated entry": one account's ledger, and a statement that excludes the year-end
 * close.
 */
function postedEntry(?Asset $asset, string $on, float $amount, ?LedgerAccount $debit = null, bool $closing = false): JournalEntry
{
    // Draft FIRST, then post. `JournalLine` refuses a line on a posted entry — correctly: debits
    // would stop equalling credits and every report built on the trial balance would be wrong.
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => $on,
        'status' => 'draft',
        'is_manual' => true,
        'is_closing' => $closing,
        'asset_id' => $asset?->id,
    ]);

    $entry->lines()->create(['ledger_account_id' => ($debit ?? test()->debit)->id, 'debit' => $amount, 'credit' => 0]);
    $entry->lines()->create(['ledger_account_id' => test()->credit->id, 'debit' => 0, 'credit' => $amount]);

    $entry->update(['status' => 'posted']);

    return $entry->fresh();
}

it('says nothing when every entry in the period has a property', function () {
    // The control. A notice that appeared on clean books would be trained away in a week, and then
    // it would be there for the one period that mattered and nobody would read it.
    postedEntry($this->mall, '2026-03-10', 5000);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull();
});

it('reports the money a property-scoped statement is leaving out', function () {
    postedEntry($this->mall, '2026-03-10', 5000);
    postedEntry(null, '2026-03-12', 84300);
    postedEntry(null, '2026-03-20', 1200);

    $notice = $this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($notice)->toBe(['count' => 2, 'total' => 85500.0, 'cumulative' => false]);
});

it('sizes an entry by its debits, not by both sides', function () {
    // An entry balances, so summing debit AND credit doubles every figure — a notice reading
    // 169,000 against 84,500 of real exposure is a worse number than no notice.
    postedEntry(null, '2026-03-12', 84500);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBe(['count' => 1, 'total' => 84500.0, 'cumulative' => false]);
});

it('stays silent on an unscoped read, because nothing is being excluded', function () {
    // A consolidated read has no `whereIn`, so the entries ARE in the figures. Warning there would
    // tell the operator something is missing from a statement that contains it.
    postedEntry(null, '2026-03-12', 84300);

    expect($this->service->unallocated(null, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull();
});

it('honours the period, so last year is not reported against this month', function () {
    postedEntry(null, '2025-11-02', 9000);

    expect($this->service->unallocated([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))
        ->toBeNull()
        // …and an "as at" read with no lower bound, which is what the balance sheet passes, does
        // see it.
        ->and($this->service->unallocated([$this->mall->id], null, CarbonImmutable::parse('2026-03-31')))
        // `cumulative`, because this read has no lower bound — it is what stops a balance
        // sheet's warning being worded as though it covered a period.
        ->toBe(['count' => 1, 'total' => 9000.0, 'cumulative' => true]);
});

it('leaves the statement figures themselves untouched', function () {
    // The whole point of surfacing rather than absorbing: the property's numbers must not move.
    postedEntry($this->mall, '2026-03-10', 5000);

    $before = $this->service->incomeStatement([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    postedEntry(null, '2026-03-12', 84300);

    $after = $this->service->incomeStatement([$this->mall->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($after)->toEqual($before);
});

/**
 * The notice as the page itself words it, read off the CSV — the copy that leaves the building.
 *
 * Driving the real page is the point: the two narrowings below are per-page overrides, so a test
 * that asked the service directly would prove the service can narrow and say nothing about whether
 * any page asks it to.
 */
function unallocatedNoticeSentenceOn(string $page): ?string
{
    $heading = __('admin.journal_entries.unallocated.heading');
    $csv = Livewire::test($page)->instance()->reportCsv();

    return collect($csv['rows'])
        ->first(fn ($row) => is_string($row[0] ?? null) && str_starts_with($row[0], $heading))[0] ?? null;
}

describe('the notice counts the population its own statement shows', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
        app(FiscalCalendar::class)->ensureYear((int) now()->year);

        $this->actingAs(makeUser('super_admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->mall);
    });

    afterEach(fn () => Filament::setTenant(null, isQuiet: true));

    it('does not count the year-end close on a statement that excludes it', function () {
        // The close posts one entry per property AND a CONSOLIDATED one for the null-asset bucket
        // (`profitLossBalancesByAsset()` — "so no P&L is ever stranded"), so a null-asset closing
        // entry exists on every install that has closed a year. The income statement and the cash
        // flow pass `excludeClosing: true`, so counting it there sized the warning at roughly twice
        // the money actually missing: the unallocated P&L, plus the entry that closes it.
        postedEntry(null, now()->toDateString(), 84300);
        postedEntry(null, now()->toDateString(), 50000, closing: true);

        expect(unallocatedNoticeSentenceOn(IncomeStatement::class))->toContain('84,300.00')
            ->and(unallocatedNoticeSentenceOn(IncomeStatement::class))->not->toContain('134,300.00')
            // …and the statements that DO include closing entries must still count both, or the
            // opposite bug ships: a trial balance whose figures carry the close, warning about less
            // than it is hiding.
            ->and(unallocatedNoticeSentenceOn(TrialBalance::class))->toContain('134,300.00');
    });

    it('counts only the chosen account on the general ledger', function () {
        // That page is ONE account's movements. A portfolio-wide count beside it reads as though
        // this account were missing money it never had.
        $other = LedgerAccount::where('type', 'expense')
            ->where('is_postable', true)
            ->whereKeyNot($this->debit->id)
            ->firstOrFail();

        postedEntry(null, now()->toDateString(), 7000);
        postedEntry(null, now()->toDateString(), 61000, debit: $other);

        $on = fn (int $accountId) => collect(
            Livewire::test(GeneralLedger::class)->set('accountId', $accountId)->instance()->reportCsv()['rows']
        )->first(fn ($row) => is_string($row[0] ?? null)
            && str_starts_with($row[0], __('admin.journal_entries.unallocated.heading')))[0] ?? null;

        expect($on($this->debit->id))->toContain('7,000.00')->not->toContain('61,000.00')
            ->and($on($other->id))->toContain('61,000.00')->not->toContain('7,000.00');

        // **The credit side, which the first version of this test could not see.** Both accounts
        // above are expenses, i.e. both DEBITED — so the assertions passed without ever exercising
        // the half that was broken. `unallocated()` sizes an entry by its debits, which is right for
        // a whole entry (it balances) and false for ONE LEG: a normally-credited account — bank, AP,
        // VAT payable, deposits held — contributes no debit at all, and the notice read
        // *"2 journal entries … totalling EGP 0.00"*. A warning naming zero money reads as "nothing
        // is missing", which is the failure the notice exists to prevent.
        expect($on($this->credit->id))->toContain('68,000.00');
    });

    it('does not warn about an account nobody has chosen', function () {
        // With no account picked the page is an unanswered question, not an empty statement — and a
        // notice saying "They are NOT in the figures above" about figures that do not exist is a
        // warning about nothing. Null from `unallocatedAccountId()` does not suppress it: null there
        // means the WIDEST population, which is why this needed its own predicate.
        //
        // Read off the RENDERED page, because the screen is the only surface that had it:
        // `reportCsv()` refuses outright with no account chosen.
        postedEntry(null, now()->toDateString(), 84300);

        Livewire::test(GeneralLedger::class)
            ->assertDontSee(__('admin.journal_entries.unallocated.heading'));

        // The control, or the assertion above passes just as happily on a notice that never renders.
        Livewire::test(GeneralLedger::class)
            ->set('accountId', $this->debit->id)
            ->assertSee(__('admin.journal_entries.unallocated.heading'));
    });

    it('prints the same figure on the PDF as on the screen it was printed from', function () {
        // The PDF took both new arguments' defaults for a day, so an income statement went out of
        // the building quoting 134,300 while the screen said 84,300 — one statement, two figures,
        // and the PDF is the copy an auditor reads.
        //
        // Asserted on what the PDF ASKS FOR rather than by inflating its compressed streams, which
        // is the same reason `PdfDocument::html()` exists: a test that has to unpack a PDF to find
        // out whether it says 84,300 does not get written, and the one written instead proves
        // nothing. The recorder sits on the service both copies read.
        postedEntry(null, now()->toDateString(), 84300);
        postedEntry(null, now()->toDateString(), 50000, closing: true);

        // An `ArrayObject`, not an array: a closure captures an array BY VALUE (an arrow function
        // always does, and a promoted property cannot be a reference), so the recorder would append
        // to its own copy and the test would read an empty list and pass for the wrong reason.
        $asked = new ArrayObject;

        app()->bind(LedgerReportService::class, fn () => new class($asked) extends LedgerReportService
        {
            public function __construct(private ArrayObject $asked) {}

            public function unallocated(?array $assetIds, ?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $excludeClosing = false, ?int $accountId = null): ?array
            {
                $this->asked[] = ['excludeClosing' => $excludeClosing, 'cumulative' => $from === null];

                return parent::unallocated($assetIds, $from, $to, $excludeClosing, $accountId);
            }
        });

        // **The PDF service directly, never through the page.** Driving `download_pdf` re-renders
        // the screen in the same request, and the screen asks `unallocated()` too — so the recorder
        // filled up with the SCREEN's (already correct) arguments and the assertions passed with the
        // PDF's fix deleted. Same trap as a lock spy seeing another service's lock: isolate first,
        // then mutate.
        $pdf = app(LedgerReportPdfService::class);
        $ids = [$this->mall->id];
        $from = CarbonImmutable::parse(now()->startOfYear()->toDateString());
        $to = CarbonImmutable::parse(now()->endOfYear()->toDateString());

        $pdf->incomeStatement($ids, $from, $to, 'Mall', 'FY');
        $pdf->balanceSheet($ids, $to, 'Mall');
        $pdf->trialBalance($ids, $from, $to, 'Mall', 'FY');

        expect($asked->getArrayCopy())->not->toBeEmpty('the PDF never asked for the notice at all');

        // The income statement's PDF excludes the close, exactly as its figures do.
        expect(collect($asked->getArrayCopy())->contains(fn (array $a) => $a['excludeClosing'] === true))->toBeTrue(
            'the income statement PDF counted the year-end close its own figures leave out');

        // The balance sheet's is a cumulative read, so it must not be worded as a period.
        expect(collect($asked->getArrayCopy())->contains(fn (array $a) => $a['cumulative'] === true))->toBeTrue(
            'the balance sheet PDF counted a bounded period for an "as at" statement');

        // …and the trial balance, which includes the close, must NOT have excluded it.
        expect(collect($asked->getArrayCopy())->contains(fn (array $a) => $a['excludeClosing'] === false && $a['cumulative'] === false))->toBeTrue(
            'the trial balance PDF dropped closing entries its own figures carry');
    });

    it('does not call a cumulative read "this period"', function () {
        // A balance sheet is an *as at* statement: it overrides `unallocatedRange()` to open-ended,
        // so it counts everything up to the date. Worded with the period sentence it claims a span
        // it did not read — an operator reconciling August against "this period holds 47 entries"
        // is chasing a figure that includes every year before it.
        postedEntry(null, now()->subYears(2)->toDateString(), 12000);
        postedEntry(null, now()->toDateString(), 3000);

        expect(unallocatedNoticeSentenceOn(BalanceSheet::class))
            ->toContain(trim(__('admin.journal_entries.unallocated.body_as_at', [
                'count' => '2', 'total' => '15,000.00', 'currency' => config('app.currency', 'EGP'),
            ])))
            // The period-worded statement beside it is unchanged, and sees only this year's.
            ->and(unallocatedNoticeSentenceOn(IncomeStatement::class))->toContain('3,000.00');
    });
});
