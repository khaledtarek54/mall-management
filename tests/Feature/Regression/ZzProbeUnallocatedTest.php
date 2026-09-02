<?php

use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
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

    $this->asset = makeAsset(['code' => 'PRB']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);
});

function probeEntry(string $date, float $amount, bool $closing = false, string $revenueSide = 'admin_expense'): JournalEntry
{
    $entry = JournalEntry::create([
        'asset_id' => null,
        'entry_date' => $date,
        'description' => 'probe',
        'status' => 'draft',
        'is_closing' => $closing,
    ]);

    $resolver = app(AccountResolver::class);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $resolver->id($revenueSide, null),
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

it('PROBE A: the notice counts CLOSING entries the income statement deliberately excludes', function () {
    $month = CarbonImmutable::now()->startOfMonth();

    probeEntry($month->addDays(3)->toDateString(), 1000.0);              // a real omission
    probeEntry($month->addDays(5)->toDateString(), 90000.0, closing: true); // a year-end close bucket

    $svc = app(LedgerReportService::class);
    $ids = [$this->asset->id];

    $notice = $svc->unallocated($ids, $month, $month->endOfMonth());

    dump(['notice' => $notice]);

    // The income statement itself excludes is_closing rows entirely.
    expect($notice['count'])->toBe(2)
        ->and($notice['total'])->toBe(91000.0);
});

it('PROBE B: an income-statement SPREAD exports a notice counted over the wrong window', function () {
    $year = CarbonImmutable::now()->year;

    // Something unallocated in JANUARY; the operator is looking at MARCH with a YTD column.
    probeEntry(CarbonImmutable::create($year, 1, 10)->toDateString(), 55555.0);
    probeEntry(CarbonImmutable::create($year, 3, 10)->toDateString(), 111.0);

    $csv = asTenant($this->asset, function () use ($year): array {
        $p = app(IncomeStatement::class);
        $p->mount();
        $p->year = $year;
        $p->period = sprintf('%d-03', $year);
        $p->spread = IncomeStatement::SPREAD_YTD;

        return $p->reportCsv();
    });

    $text = collect($csv['rows'])->flatten()->filter()->implode(' | ');

    dump(['headers' => $csv['headers']]);
    dump(['notice_line' => collect($csv['rows'])->flatten()->filter()
        ->first(fn ($v) => is_string($v) && str_contains($v, 'Not included'))]);

    // The YTD column covers Jan-Mar and omits 55,555 too, but the notice says only 111.
    expect($text)->toContain('Not included');
});

it('PROBE C: the notice row travels into the XLSX / row count', function () {
    $month = CarbonImmutable::now()->startOfMonth();
    probeEntry($month->addDays(3)->toDateString(), 4242.0);

    $with = asTenant($this->asset, function (): array {
        $p = app(TrialBalance::class);
        $p->mount();

        return $p->reportCsv();
    });

    dump(['last_two_rows' => array_slice($with['rows'], -2)]);
    dump(['header_count' => count($with['headers']), 'last_row_width' => count(end($with['rows']))]);

    expect(true)->toBeTrue();
});
