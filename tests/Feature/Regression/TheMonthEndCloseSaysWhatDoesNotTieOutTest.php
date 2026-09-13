<?php

use App\Filament\Admin\Pages\MonthEndClose;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

/**
 * **When the books do not tie out, the month-end checklist says WHICH check failed and BY HOW
 * MUCH — on the row, in the reader's language.**
 *
 * Driven as the accountant (2026-09-13 workflow audit): the row read *"Books tie out · Blocks · 1"*
 * and nothing else. The failing check's name sat in a hover tooltip on the count — invisible on
 * touch, easy to miss — in the console audit's English, with no figure on it. An accountant can
 * see that something is off and not what, nor by how much; the console (`billing:reconcile`) had
 * the answer and the screen that gates the close did not.
 *
 * The check's name comes from `admin.month_end.checks.{key}` with the console label as the floor;
 * the control-account tie-out carries its FIGURES — ledger, documents, difference — read from the
 * same `glTieOut()` the check made, never parsed back out of its English sentence.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'MEC']);
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // Drift the ledger away from the documents: a manual entry onto the AR control account with
    // no invoice behind it. The tie-out must now report AR off by exactly this.
    $ar = app(AccountResolver::class);
    app(JournalPostingService::class)->post(['entry_date' => '2026-06-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $ar->id('accounts_receivable'), 'debit' => 43470, 'credit' => 0],
        ['ledger_account_id' => $ar->id('rent_revenue'), 'debit' => 0, 'credit' => 43470],
    ]]);

    $this->step = fn (): array => collect(app(MonthEndReadinessService::class)
        ->for(CarbonImmutable::parse('2026-06-01'), $this->asset->id)['steps'])
        ->firstWhere('key', 'books_tie_out');
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('names the failing check and its figures in the reader\'s language', function () {
    expect(abs(app(BooksReconciliationService::class)->glTieOut()['ar']['delta']))->toBeGreaterThan(0.005);

    app()->setLocale('en');
    $en = ($this->step)();

    expect($en['status'])->toBe(MonthEndReadinessService::BLOCKED)
        ->and($en['detail'])->toContain(__('admin.month_end.checks.gl_tie_out'))
        ->and($en['detail'])->toContain('43,470.00')
        ->and($en['detail'])->toContain('Receivables')
        // The console's label is the floor, not what the accountant reads.
        ->and($en['detail'])->not->toContain('General ledger AR/AP ties to source documents');

    app()->setLocale('ar');
    $ar = ($this->step)();

    expect($ar['detail'])->toContain(__('admin.month_end.checks.gl_tie_out', [], 'ar'))
        ->and($ar['detail'])->toContain('43,470.00')
        ->and($ar['detail'])->not->toBe($en['detail']);
});

it('shows it on the row, not only in a hover tooltip', function () {
    $html = Livewire::test(MonthEndClose::class)->set('period', '2026-06')->html();

    // Filament renders a column's description as its own element; a tooltip is an attribute.
    preg_match_all('/fi-ta-text-description[^>]*>(.*?)<\//s', $html, $descriptions);
    $visible = implode(' ', array_map(fn (string $d) => html_entity_decode(strip_tags($d)), $descriptions[1]));

    expect($visible)->toContain(__('admin.month_end.checks.gl_tie_out'))
        ->and($visible)->toContain('43,470.00');
});

it('still explains the step\'s purpose when it is clear', function () {
    // Undo the drift: reverse the entry, and the row goes back to saying WHY it exists.
    $entry = JournalEntry::query()->where('status', 'posted')->latest('id')->firstOrFail();
    app(JournalPostingService::class)->void($entry, 'test cleanup');

    expect(abs(app(BooksReconciliationService::class)->glTieOut()['ar']['delta']))->toBeLessThan(0.005);

    $html = Livewire::test(MonthEndClose::class)->set('period', '2026-06')->html();
    preg_match_all('/fi-ta-text-description[^>]*>(.*?)<\//s', $html, $descriptions);
    $visible = implode(' ', array_map(fn (string $d) => html_entity_decode(strip_tags($d)), $descriptions[1]));

    expect($visible)->toContain(__('admin.month_end.why.books_tie_out'))
        ->and($visible)->not->toContain('43,470.00');
});

it('names every check the audit can fail, in both languages, with the console label as the floor', function () {
    $keys = ['invoice_composition', 'paid_amount', 'balance', 'payment_allocation', 'marketing_budget',
        'cam_allocations', 'gl_tie_out', 'gl_in_sync', 'deposits_tie_out'];

    foreach ($keys as $key) {
        foreach (['en', 'ar'] as $locale) {
            expect(Lang::has("admin.month_end.checks.{$key}", $locale, fallback: false))
                ->toBeTrue("{$key} [{$locale}]");
        }
    }

    // The checks' keys are DERIVED from the service's source, so a tenth check cannot ship unnamed.
    $source = file_get_contents(app_path('Services/Reconciliation/BooksReconciliationService.php'));
    preg_match_all("/->check\(\s*'([a-z_]+)'/", $source, $m);

    expect(array_values(array_unique($m[1])))->toEqualCanonicalizing($keys);
});
