<?php

use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'PB3']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);
});

function probe3(string $date, float $amount, bool $closing, string $role): JournalEntry
{
    $entry = JournalEntry::create([
        'asset_id' => null, 'entry_date' => $date, 'description' => 'probe',
        'status' => 'draft', 'is_closing' => $closing,
    ]);
    $r = app(AccountResolver::class);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $r->id($role, null), 'debit' => $amount, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $r->id('bank', null), 'debit' => 0, 'credit' => $amount]);
    $entry->update(['status' => 'posted']);

    return $entry->fresh();
}

it('PROBE E: is_closing really persists, and the income statement CSV notice counts it', function () {
    $m = CarbonImmutable::now()->startOfMonth();
    $closing = probe3($m->addDays(5)->toDateString(), 90000.0, true, 'admin_expense');

    dump(['is_closing_persisted' => (bool) $closing->fresh()->is_closing]);
    expect((bool) $closing->fresh()->is_closing)->toBeTrue();

    // The income statement itself sees NOTHING (excludeClosing) …
    $is = app(LedgerReportService::class)->incomeStatement(null, $m, $m->endOfMonth());
    dump(['income_statement_total_expense_unscoped' => $is['total_expense']]);

    // … but the exported notice announces 90,000 of "money left out".
    $csv = asTenant($this->asset, function (): array {
        $p = app(IncomeStatement::class);
        $p->mount();

        return $p->reportCsv();
    });
    $line = collect($csv['rows'])->flatten()->filter()
        ->first(fn ($v) => is_string($v) && str_contains($v, 'Not included'));
    dump(['income_statement_csv_notice' => $line]);
});

it('PROBE F: the General Ledger of ONE account warns about entries in OTHER accounts', function () {
    $m = CarbonImmutable::now()->startOfMonth();
    probe3($m->addDays(3)->toDateString(), 8888.0, false, 'admin_expense');

    // Look at a totally unrelated account — accounts receivable.
    $ar = LedgerAccount::query()->where('code', app(AccountResolver::class)->account('ar', null)->code)->first();

    $csv = asTenant($this->asset, function () use ($ar): array {
        $p = app(GeneralLedger::class);
        $p->mount();
        $p->accountId = $ar->id;

        return $p->reportCsv();
    });

    $line = collect($csv['rows'])->flatten()->filter()
        ->first(fn ($v) => is_string($v) && str_contains($v, 'Not included'));

    dump(['gl_account' => $ar->code.' '.$ar->name_en, 'data_rows' => count($csv['rows']), 'notice' => $line]);
});
