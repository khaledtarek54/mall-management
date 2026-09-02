<?php

use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\Finder\Finder;

/**
 * THE WARNING WAS ON THE SCREEN AND ON NOTHING THAT LEAVES THE BUILDING.
 *
 * Every ledger report scopes with `whereIn('je.asset_id', $ids)`, and **`whereIn` never matches
 * NULL** — so a journal entry filed against no property is invisible in all five statements. EG-27
 * put a notice beside them saying how much, because the alternative (folding those rows in) shows
 * one operator-wide cost in full on every mall.
 *
 * It was rendered by `ledger-report.blade.php` and by nothing else. The PDF, the CSV export, the
 * scheduled email and the owner's pack omitted the same money with nothing on them to say so — and
 * those are the copies that go to an accountant, an owner or an auditor, none of whom can open the
 * ledger to find out. The screen is the one surface whose reader could already have seen it.
 *
 * **Silent on clean books**, on every surface, for the reason the on-screen one is: a warning shown
 * on a healthy period is trained away long before the period that matters, and a trailing row on
 * every statement reads as boilerplate.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset(['code' => 'UNA']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);
});

/** A balanced entry filed against NO property — the row every statement scopes away. */
function unallocatedEntry(float $amount): JournalEntry
{
    $entry = JournalEntry::create([
        'asset_id' => null,
        'entry_date' => CarbonImmutable::now()->startOfMonth()->addDays(3)->toDateString(),
        'description' => 'Operator-wide insurance',
        // DRAFT while the lines are written — `JournalLine` refuses a write against a posted entry,
        // because debits would stop equalling credits. Posted below, once it balances.
        'status' => 'draft',
    ]);

    $resolver = app(AccountResolver::class);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $resolver->id('admin_expense', null),
        'debit' => $amount, 'credit' => 0,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $resolver->id('bank', null),
        'debit' => 0, 'credit' => $amount,
    ]);

    $entry->update(['status' => 'posted']);

    return $entry->fresh();
}

it('carries the warning on the CSV, not only on the screen', function () {
    unallocatedEntry(12500);

    $page = asTenant($this->asset, function () {
        $p = app(TrialBalance::class);
        $p->mount();

        return $p;
    });

    $csv = asTenant($this->asset, fn (): array => $page->reportCsv());

    $text = collect($csv['rows'])->flatten()->filter()->implode(' | ');

    expect($text)->toContain('12,500.00')
        ->and($text)->toContain(__('admin.journal_entries.unallocated.heading'));
});

it('says nothing on clean books', function () {
    // The control, and the rule: a notice shown on a healthy period is trained away before the
    // period that matters. A fix that appended a row unconditionally would satisfy the case above
    // and make the warning worthless.
    $page = asTenant($this->asset, function () {
        $p = app(TrialBalance::class);
        $p->mount();

        return $p;
    });

    $csv = asTenant($this->asset, fn (): array => $page->reportCsv());

    $text = collect($csv['rows'])->flatten()->filter()->implode(' | ');

    expect($text)->not->toContain(__('admin.journal_entries.unallocated.heading'));
});

it('renders the warning from the SHARED layout, so a sixth statement inherits it', function () {
    // The layout is the seam — five statements share it, and putting the notice in each template
    // would make the sixth the one that quietly omits money. Asserted on the template rather than
    // by inflating a PDF's compressed streams, which is the trade this codebase already records for
    // `PdfDocument::html()`.
    $layout = file_get_contents(base_path('resources/views/accounting/pdf/layout.blade.php'));

    // Through the SHARED wording, not its own interpolation. Four renderers word this sentence —
    // screen, CSV, the scheduled email's copy of that CSV, and this PDF — and three of them
    // interpolated the same placeholders separately, which is how the PDF came to quote 134,300
    // while the screen it was printed from said 84,300.
    expect($layout)->toContain('UnallocatedNotice::heading()')
        ->and($layout)->toContain('UnallocatedNotice::sentence($unallocated)')
        ->and($layout)->not->toContain("__('admin.journal_entries.unallocated.body")
        // …and only when there is something to say.
        ->and($layout)->toContain("(\$unallocated['count'] ?? 0) > 0");

    // And the service computes it for every statement that has a window.
    $service = file_get_contents(base_path('app/Services/Accounting/LedgerReportPdfService.php'));

    expect(substr_count($service, 'window: ['))->toBe(4)
        ->and($service)->toContain("'unallocated' => \$unallocated");
});

it('has every ledger report carrying it on its CSV', function () {
    // The gate. `unallocatedNotice()` lives on the concern so a sixth statement inherits the
    // warning; the CSV half has to be wrapped per report, which is exactly the kind of step that
    // gets forgotten — so it is checked rather than trusted.
    $offenders = [];
    $examined = 0;

    foreach (Finder::create()->files()->in(app_path('Filament/Admin/Pages'))->name('*.php') as $file) {
        $source = $file->getContents();

        // `use ScopesLedgerReport;` OR `use ScopesLedgerReport {` — `WithholdingTaxReturn` pulls
        // it in with an ALIAS BLOCK, and a matcher that only knew the plain form swept six files
        // and reported on seven.
        if (! preg_match('/use ScopesLedgerReport\s*[;{]/', $source)) {
            continue;
        }

        if (! str_contains($source, 'function reportCsv')) {
            continue;   // not deliverable — nothing to carry the notice on
        }

        $examined++;

        if (! str_contains($source, 'withUnallocatedNotice(')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], 'These ledger reports export a CSV without the unallocated-entries '
        .'warning, so the emailed copy omits money with nothing to say so: '.implode(', ', $offenders))
        // What the sweep EXAMINED, not what a constant holds — a matcher that stopped matching
        // would otherwise report zero offenders out of zero files.
        ->and($examined)->toBe(7);

    // …and the helper is on the concern, so it cannot drift into seven copies.
    expect(method_exists(TrialBalance::class, 'reportCsv'))->toBeTrue()
        ->and(in_array(ScopesLedgerReport::class, class_uses_recursive(TrialBalance::class), true))->toBeTrue();
});
