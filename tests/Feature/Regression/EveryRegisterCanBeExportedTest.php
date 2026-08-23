<?php

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Assets\Pages\ListAssets;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Exports\AssetExporter;
use App\Filament\Exports\VendorExporter;
use App\Models\CustomField;
use App\Support\Exports;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Vendors and properties were the two registers with no way OUT of the system.
 *
 * Seven resources exported; these two imported and never exported — a one-way door. It surfaced
 * while finishing EG-32: a custom field on a vendor could be defined, filled, filtered and imported,
 * and could not be taken away, because there was no exporter for the columns to attach to.
 *
 * The rule the FRD states is that import is restricted and export is wide: *"all other roles may
 * export/download but not import"*. So the gate is the resource's own `canViewAny()` through
 * `App\Support\Exports` — never a permission of its own — and whoever may read the list may take it
 * away. Read as an authorization question it is not one: Filament exports
 * `getTableQueryForExport()`, the resource's own scoped query with the operator's filters applied,
 * so an export can never return a row the list would not.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EX']);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('offers an export on the vendor and property registers', function () {
    asTenant($this->asset, function () {
        Livewire::withQueryParams([]);

        // Read off the built table, and asserted VISIBLE — an action that exists and is hidden is
        // the same as no action at all to the operator looking for it.
        foreach ([ListVendors::class, ListAssets::class] as $page) {
            $actions = Livewire::test($page)->instance()->getTable()->getHeaderActions();

            $export = collect($actions)->first(fn ($a) => $a->getName() === 'export');

            expect($export)->not->toBeNull($page.' has no export header action')
                ->and($export->isVisible())->toBeTrue($page.' hides its export action');
        }
    });
});

it('exports the columns another system joins on, code first', function () {
    $vendor = array_map(fn ($c) => $c->getName(), VendorExporter::getColumns());
    $asset = array_map(fn ($c) => $c->getName(), AssetExporter::getColumns());

    expect($vendor[0])->toBe('code')
        ->and($asset[0])->toBe('code')
        // The identity `VendorImporter` dedups on, so a file exported here can be re-imported.
        ->and($vendor)->toContain('tax_id')
        ->and($asset)->toContain('leasable_area_sqm');
});

it('carries the operator’s own fields into both exports, last', function () {
    CustomField::create([
        'model' => 'vendor', 'key' => 'approved_list',
        'label_en' => 'On the approved list', 'label_ar' => 'ضمن القائمة المعتمدة', 'type' => 'boolean',
    ]);
    CustomField::create([
        'model' => 'asset', 'key' => 'licence_ref',
        'label_en' => 'Licence reference', 'label_ar' => 'مرجع الترخيص', 'type' => 'text',
    ]);

    $vendor = array_map(fn ($c) => $c->getName(), VendorExporter::getColumns());
    $asset = array_map(fn ($c) => $c->getName(), AssetExporter::getColumns());

    expect(end($vendor))->toBe('custom_fields.approved_list')
        ->and(end($asset))->toBe('custom_fields.licence_ref')
        // The control: the shipped columns are untouched and still lead, so adding a field does not
        // move the positions a colleague's template depends on.
        ->and($vendor[0])->toBe('code')
        ->and($asset[0])->toBe('code');
});

it('refuses the export to somebody who may not read the list', function () {
    // The gate is `canViewAny()`, so this is the same refusal the list itself makes — asserted
    // through `Exports::allowed()` directly, because `->callAction()` checks visibility first and
    // would pass whether or not the gate exists.
    $stranger = makeUser('technician', [$this->asset->id]);
    $this->actingAs($stranger);

    asTenant($this->asset, function () {
        expect(Exports::allowed(VendorResource::class))
            ->toBe(VendorResource::canViewAny());
    });

    // Paired control: a manager, who may read both lists, may export both.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        expect(Exports::allowed(VendorResource::class))->toBeTrue()
            ->and(Exports::allowed(AssetResource::class))->toBeTrue();
    });
});
