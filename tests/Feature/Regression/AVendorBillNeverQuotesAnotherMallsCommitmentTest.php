<?php

/*
|--------------------------------------------------------------------------
| A vendor bill never quotes another mall's commitment (SW-080)
|--------------------------------------------------------------------------
| Two reads on the bill form resolved a record with a bare `find()`:
|
|     VendorContract::find($get('vendor_contract_id'))     // the commitment helper text
|     PurchaseRequest::find($get('purchase_request_id'))   // the three-way-match placeholder
|
| Both ids come out of the Livewire payload and both fields are `->live()`, so the figures render
| before any save. The SAVE was never at risk — `EntitySelect` labels a submitted value through
| `OptionDisplay::pickable()`'s scoped query and refuses what it cannot label — which is exactly why
| this survived: everything downstream of it was airtight, and the leak was the sentence UNDER the
| field. Same oracle `PurchaseRequest::saving()` already names for warehouses.
|
| Each refusal is paired with the control that must still print the arithmetic, because a helper
| text that had simply stopped working would satisfy the refusals on its own.
*/

use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorContract;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'VBA']);
    $this->otherMall = makeAsset(['code' => 'VBB']);

    $this->vendor = Vendor::create(['name' => 'Otis Lifts', 'status' => 'active']);

    $this->ownContract = VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->mall->id,
        'name' => 'Lift maintenance — this mall', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 120000,
    ]);

    $this->foreignContract = VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->otherMall->id,
        'name' => 'Lift maintenance — the other mall', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 987654,
    ]);

    $this->ownPurchase = PurchaseRequest::create([
        'asset_id' => $this->mall->id, 'vendor_id' => $this->vendor->id,
        'status' => PurchaseRequest::STATUS_RECEIVED,
        'justification' => 'Lift spares', 'total_value' => 45000,
    ]);

    $this->foreignPurchase = PurchaseRequest::create([
        'asset_id' => $this->otherMall->id, 'vendor_id' => $this->vendor->id,
        'status' => PurchaseRequest::STATUS_RECEIVED,
        'justification' => 'Lift spares — the other mall', 'total_value' => 876543,
    ]);

    // The operator holds ONE mall. `manager` carries view/create/edit on every module.
    $this->actingAs(makeUser('manager', [$this->mall->id]));
});

it('never prints another mall’s contract commitment under the contract picker', function () {
    asTenant($this->mall, function () {
        $page = Livewire::test(CreateVendorBill::class)->assertOk();

        // Read what the OPERATOR sees, not an internal accessor: Filament v4 composes
        // `helperText()` into `belowContent()` and exposes no `getHelperText()` at all, so the
        // obvious reader is a BadMethodCall rather than a wrong answer. The rendered page is also
        // the honest subject — a figure the form computes but never paints leaks to nobody.
        $helper = fn (): string => $page->html();

        // THE CONTROL FIRST. With this mall's own contract picked the sentence really does spell
        // out the arithmetic, so the refusal below cannot pass because the helper is broken.
        $page->fillForm([
            'vendor_id' => $this->vendor->id,
            'vendor_contract_id' => $this->ownContract->id,
        ]);

        expect($helper())->toContain(number_format(120000, 2));

        // The other mall's contract, arriving the only way it can: in the payload.
        $page->fillForm([
            'vendor_id' => $this->vendor->id,
            'vendor_contract_id' => $this->foreignContract->id,
        ]);

        expect($helper())->not->toContain(number_format(987654, 2))
            ->and($helper())->toContain(__('admin.fields.vendor_contract_hint'));
    });
});

it('never prints another mall’s purchase order in the three-way match', function () {
    asTenant($this->mall, function () {
        $page = Livewire::test(CreateVendorBill::class)->assertOk();

        $match = fn (): string => $page->html();

        // The control: this mall's own purchase really is summarised.
        $page->fillForm([
            'vendor_id' => $this->vendor->id,
            'asset_id' => $this->mall->id,
            'purchase_request_id' => $this->ownPurchase->id,
        ]);

        expect($match())->toContain(number_format(45000, 2));

        $page->fillForm([
            'vendor_id' => $this->vendor->id,
            'asset_id' => $this->mall->id,
            'purchase_request_id' => $this->foreignPurchase->id,
        ]);

        expect($match())->not->toContain(number_format(876543, 2));
    });
});
