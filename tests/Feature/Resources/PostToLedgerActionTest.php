<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Models\JournalEntry;
use App\Models\SystemSetting;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('shows the "ledger last synced" subheading on the journal entries list', function () {
    Livewire::test(ListJournalEntries::class)
        ->assertOk()
        ->assertSee('not yet synced'); // the never-synced copy
});

it('posts to the general ledger from the UI and records the last-synced time', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);

    Livewire::test(ListJournalEntries::class)
        ->callAction('post_to_ledger')
        ->assertHasNoActionErrors();

    expect(JournalEntry::where('status', 'posted')->where('source_id', $invoice->id)->exists())->toBeTrue();
    expect(SystemSetting::get('ledger_last_synced_at'))->not->toBeNull();
});

it('exposes the post-to-ledger action on the trial balance page', function () {
    Livewire::test(TrialBalance::class)
        ->assertOk()
        ->assertActionVisible('post_to_ledger');
});

it('hides post-to-ledger from a user who cannot post journal entries', function () {
    $user = makeUser('viewer');
    $user->givePermissionTo('journal_entries.view');
    $this->actingAs($user);

    Livewire::test(ListJournalEntries::class)
        ->assertActionHidden('post_to_ledger');
});
