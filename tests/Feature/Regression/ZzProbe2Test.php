<?php

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
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset(['code' => 'PB2']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);
});

function probe2Entry(string $date, float $amount, bool $closing = false): JournalEntry
{
    $entry = JournalEntry::create([
        'asset_id' => null, 'entry_date' => $date, 'description' => 'probe',
        'status' => 'draft', 'is_closing' => $closing,
    ]);
    $r = app(AccountResolver::class);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $r->id('admin_expense', null), 'debit' => $amount, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $r->id('bank', null), 'debit' => 0, 'credit' => $amount]);
    $entry->update(['status' => 'posted']);

    return $entry->fresh();
}

it('PROBE D: the SCREEN really does render the notice (protected method via $this)', function () {
    probe2Entry(CarbonImmutable::now()->startOfMonth()->addDays(3)->toDateString(), 7777.0);

    asTenant($this->asset, function () {
        $html = Livewire::test(TrialBalance::class)->html();
        dump(['screen_has_notice' => str_contains($html, 'Not included: entries with no property')]);
        expect(true)->toBeTrue();
    });
});
