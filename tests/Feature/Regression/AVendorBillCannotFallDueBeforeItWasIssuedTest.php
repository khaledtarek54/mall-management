<?php

/*
|--------------------------------------------------------------------------
| A vendor bill cannot fall due before it was issued (SW-081)
|--------------------------------------------------------------------------
| Nothing paired `bill_date` and `due_date` on the AP side. A mistyped year saved cleanly and the
| bill then read as permanently OVERDUE: `VendorBillResource::getNavigationBadge()` counts
| `balance > 0 AND due_date < today`, so the red badge carried a number nothing on the list could
| explain. The AR twin has had the rule since it shipped (`InvoiceForm`: `after('issue_date')`).
|
| `afterOrEqual`, not `after`. "Due on receipt" is ordinary supplier terms and an EG-33 recurring
| schedule with `payment_terms_days = 0` produces exactly that pair, so the equal case is a CONTROL
| here and not an oversight — a strict `after` would refuse a legitimate payable.
|
| The rule lifts once the bill leaves draft, because Filament validates a disabled field anyway and
| a bill keyed before this rule existed must stay editable on the one field that stays open past
| draft. That is asserted too: a refusal with no way out is worse than the bug.
*/

use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'VBD']);
    $this->vendor = Vendor::create(['name' => 'Nile Facilities', 'status' => 'active']);

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->payload = fn (string $billDate, ?string $dueDate): array => [
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'category' => 'maintenance',
        'bill_date' => $billDate,
        'due_date' => $dueDate,
        'subtotal' => 1000,
        'vat_amount' => 0,
        'total' => 1000,
    ];
});

it('refuses a due date earlier than the bill date', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateVendorBill::class)
            ->fillForm(($this->payload)('2026-09-10', '2026-09-01'))
            ->call('create')
            ->assertHasFormErrors(['due_date']);
    });

    expect(VendorBill::count())->toBe(0);
});

it('accepts a bill due on the day it was issued', function () {
    // The control that decides `afterOrEqual` rather than `after`: due-on-receipt is ordinary
    // supplier terms, and `payment_terms_days = 0` on a recurring schedule mints exactly this.
    asTenant($this->asset, function () {
        Livewire::test(CreateVendorBill::class)
            ->fillForm(($this->payload)('2026-09-10', '2026-09-10'))
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect(VendorBill::count())->toBe(1)
        ->and(VendorBill::first()->due_date->toDateString())->toBe('2026-09-10');
});

it('still accepts an ordinary 30-day payable, and a blank due date', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateVendorBill::class)
            ->fillForm(($this->payload)('2026-09-10', '2026-10-10'))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateVendorBill::class)
            ->fillForm(array_merge(($this->payload)('2026-09-11', null), ['reference' => 'SUP-2']))
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect(VendorBill::count())->toBe(2);
});

it('does not lock an already-approved bill that was keyed before the rule existed', function () {
    // The escape. A bill saved with an inverted pair before this rule shipped must still be
    // editable on the one field that stays open past draft — the work-order link — or the fix
    // strands exactly the records it was written for. Written straight to the table because no
    // screen can produce this state any more.
    $bill = VendorBill::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => '2026-09-10', 'due_date' => '2026-09-01',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
    ]);

    asTenant($this->asset, function () use ($bill) {
        Livewire::test(EditVendorBill::class, ['record' => $bill->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    });
});
