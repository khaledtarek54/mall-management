<?php

/*
| PARKED with the ETA freeze (2026-08-22). `Modules::enabled('eta')` now answers false
| unconditionally from `App\Support\Modules::FROZEN`, so these surfaces cannot be reached from any
| test either — the module flag is no longer a settings row a test can flip.
|
| Skipped rather than deleted, because the code they cover is intact and this is the coverage that
| proves it still works the day module 16 resumes: delete the `eta` entry from `Modules::FROZEN` and
| these go green again unchanged. The invisibility they used to assert the other way round is now
| `tests/Feature/Regression/EtaIsFrozenAndInvisibleTest.php`.
*/

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    // ETA is postponed/off by default — these filters are module-gated, so enable
    // the module to exercise them.
    $settings = app(ModulesSettings::class);
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
})->skip('ETA is frozen (App\Support\Modules::FROZEN) — this surface cannot render. Unfreeze the module to run it.');

it('needs_eta_attention covers BOTH invalid and rejected', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('needs_eta_attention')
            ->assertCanSeeTableRecords([$this->invalid, $this->rejected])
            ->assertCanNotSeeTableRecords([$this->valid, $this->submitted, $this->pendingNull, $this->pendingString]);
    });
})->skip('ETA is frozen (App\Support\Modules::FROZEN) — this surface cannot render. Unfreeze the module to run it.');

it('eta_pending covers BOTH null and explicit pending status', function () {
    asTenant($this->asset, function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('eta_pending')
            ->assertCanSeeTableRecords([$this->pendingNull, $this->pendingString])
            ->assertCanNotSeeTableRecords([$this->valid, $this->submitted, $this->invalid, $this->rejected]);
    });
})->skip('ETA is frozen (App\Support\Modules::FROZEN) — this surface cannot render. Unfreeze the module to run it.');
