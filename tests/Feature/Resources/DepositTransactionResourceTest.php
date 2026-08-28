<?php

use App\Filament\Admin\Resources\DepositTransactions\Pages\CreateDepositTransaction;
use App\Filament\Admin\Resources\DepositTransactions\Pages\EditDepositTransaction;
use App\Filament\Admin\Resources\DepositTransactions\Pages\ListDepositTransactions;
use App\Models\DepositTransaction;
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
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);
    $this->lease = makeLease(makeUnit($this->asset));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the deposit transactions list and create screens', function () {
    Livewire::test(ListDepositTransactions::class)->assertOk();
    Livewire::test(CreateDepositTransaction::class)->assertOk();
});

it('creates a deposit receipt (tenant/asset derived) and cancels it through the UI', function () {
    Livewire::test(CreateDepositTransaction::class)
        ->fillForm([
            'lease_id' => $this->lease->id,
            'type' => 'receipt',
            'method' => 'bank',
            'transaction_date' => now()->toDateString(),
            'amount' => 5000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = DepositTransaction::latest('id')->first();
    expect($deposit->status)->toBe('recorded');
    expect($deposit->tenant_id)->toBe($this->lease->tenant_id);
    expect($deposit->asset_id)->toBe($this->asset->id);

    Livewire::test(EditDepositTransaction::class, ['record' => $deposit->getRouteKey()])
        // A reason is now REQUIRED on every reversal (D5, 2026-08-28) — an audit control, not a
        // preference, so the action refuses without one.
        ->callAction('cancel_deposit', ['reason' => 'keyed against the wrong lease'])
        ->assertHasNoActionErrors();

    expect($deposit->fresh()->status)->toBe('cancelled');
});
