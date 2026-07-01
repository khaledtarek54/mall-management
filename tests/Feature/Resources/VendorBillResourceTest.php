<?php

use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Models\Vendor;
use App\Models\VendorBill;
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

it('renders the vendor bills list and create screens', function () {
    Livewire::test(ListVendorBills::class)->assertOk();
    Livewire::test(CreateVendorBill::class)->assertOk();
});

it('creates, approves, and pays a vendor bill through the UI', function () {
    $vendor = Vendor::factory()->create();

    Livewire::test(CreateVendorBill::class)
        ->fillForm([
            'vendor_id' => $vendor->id,
            'category' => 'maintenance',
            'bill_date' => now()->toDateString(),
            'subtotal' => 10000,
            'vat_amount' => 1400,
            'total' => 11400,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $bill = VendorBill::latest('id')->first();
    expect($bill->status)->toBe('draft');

    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->callAction('approve')
        ->assertHasNoActionErrors();
    expect($bill->fresh()->status)->toBe('approved');

    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->callAction('record_payment', data: [
            'amount' => 11400,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
        ])
        ->assertHasNoActionErrors();

    expect($bill->fresh()->status)->toBe('paid');
    expect((float) $bill->fresh()->balance)->toEqualWithDelta(0.0, 0.001);
});
