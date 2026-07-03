<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    // ETA is postponed/off by default — these filters are module-gated, so enable
    // the module to exercise them.
    $settings = app(\App\Settings\ModulesSettings::class);
    $settings->eta = true;
    $settings->save();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);

    // 4 invoices, one per ETA state we care about + one rejected.
    $this->valid = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'valid']);
    $this->submitted = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'submitted']);
    $this->invalid = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'invalid']);
    $this->rejected = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'rejected']);
    $this->pendingNull = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => null]);
    $this->pendingString = makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'pending']);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('eta_status filter narrows to one specific status', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('eta_status', 'valid')
            ->assertCanSeeTableRecords([$this->valid])
            ->assertCanNotSeeTableRecords([$this->submitted, $this->invalid, $this->rejected, $this->pendingNull]);
    });
});

it('needs_eta_attention covers BOTH invalid and rejected', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('needs_eta_attention')
            ->assertCanSeeTableRecords([$this->invalid, $this->rejected])
            ->assertCanNotSeeTableRecords([$this->valid, $this->submitted, $this->pendingNull, $this->pendingString]);
    });
});

it('eta_pending covers BOTH null and explicit pending status', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('eta_pending')
            ->assertCanSeeTableRecords([$this->pendingNull, $this->pendingString])
            ->assertCanNotSeeTableRecords([$this->valid, $this->submitted, $this->invalid, $this->rejected]);
    });
});
