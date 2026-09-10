<?php

use App\Filament\Admin\RelationManagers\AssetAreasRelationManager;
use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\Areas\Pages\EditArea;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Area;
use App\Support\WriteSurfaces;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A zone created from the property's own Zones tab can name who covers it — and only the
 * property's own staff.
 *
 * Until 2026-09-10 the tab asked for a code, a name and a toggle and NOT for supervisors, under a
 * docblock saying who covers a zone is set on the zone's own screen, one click away. A zone
 * ROUTES — `TenantRequest` and `FacilityWorkOrder` both fan out to its supervisors — so a zone
 * created here and never opened again routed to nobody, silently. The write-surface gate
 * (`WriteSurfacesConformanceTest`) registered it as the one divergence between a relation manager
 * and its record's own form; this is the change that closed it.
 *
 * Both halves are driven through the REAL relation manager, because the option list a picker
 * offers is a convenience and the post-save re-validation is the gate — and a test that seeded the
 * pivot by hand would prove the guard and nothing about the tab.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'ZTA']);
    $this->actingAs(makeUser('super_admin'));
});

it('creates a zone with its supervisors from the property page', function () {
    $staff = makeUser('operations', [$this->asset->id]);

    Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])
        ->callTableAction('create', data: [
            'code' => 'FC',
            'name' => 'Food Court',
            'is_active' => true,
            'supervisors' => [$staff->id],
        ])
        ->assertHasNoTableActionErrors();

    $zone = Area::where('asset_id', $this->asset->id)->where('code', 'FC')->first();

    expect($zone)->not->toBeNull()
        ->and($zone->supervisors()->pluck('users.id')->all())->toBe([$staff->id]);
});

it('refuses another mall’s staff as a supervisor, even when the id is smuggled in', function () {
    // TWO layers refuse this, and the test names which one it is proving.
    //
    // The FIRST is Filament's own: a Select derives `Rule::in` from the options it resolved, and
    // `AreaForm::applySupervisorScope()` keyed on the owner record never offers mall B's staff —
    // so the submit is refused at validation, before the action body runs. That is upstream's
    // contract, pinned here the way `FilamentActionDispatchContractTest` pins its sibling, so a
    // Filament release that stops deriving the rule turns this red.
    //
    // The SECOND is ours: `->after()` re-runs `AreaResource::assertSupervisorsInScope()`, exactly
    // as `CreateArea` does, because a relationship Select syncs from component state after the
    // model saves and an option list is a convenience, not a gate. The first cut of this comment
    // said that layer was unreachable from here — review disproved it with a SCALAR payload, which
    // is the test below.
    $other = makeAsset(['code' => 'ZTB']);
    $mallBStaff = makeUser('operations', [$other->id]);

    Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])
        ->callTableAction('create', data: [
            'code' => 'FC',
            'name' => 'Food Court',
            'is_active' => true,
            'supervisors' => [$mallBStaff->id],
        ])
        // A MULTI-select reports the rejected ITEM — `supervisors.0` — not the field, and the
        // first cut of this test asserted the field and read "missing error" as "no refusal".
        ->assertHasTableActionErrors(['supervisors.0']);

    // Nothing was written: no zone, and therefore no pivot to strip.
    expect(Area::where('asset_id', $this->asset->id)->where('code', 'FC')->exists())->toBeFalse();
});

it('refuses a scalar supervisor id — the payload shape that used to slip the array validation', function () {
    // Filament derives `Rule::in` for a `->multiple()` Select on the array's CHILDREN
    // (`supervisors.*`), so a SCALAR — `'supervisors' => 5` rather than `[5]` — had none to fail on,
    // committed the row, synced the pivot, and only then met `->after()`'s guard, which stripped
    // and 403'd — leaving an EMPTY ZONE behind until the action was made transactional. Found by
    // review with a query log, against a comment claiming this path could not reach the guard.
    //
    // `App\Support\Filament\MultiValueFieldIsAnArray` now puts an `array` rule on every
    // multi-select in every panel, so the scalar is refused at VALIDATION on the field itself and
    // nothing is written. The `->after()` guard and the transaction stay as the second layer, and
    // are UNEXERCISED through any form from here on: no payload reaches them. The guard's own
    // strip-and-403 is proved by `AreaRoutingScenarioTest`, which attaches a tampered pivot by hand.
    $other = makeAsset(['code' => 'ZTB']);
    $mallBStaff = makeUser('operations', [$other->id]);

    Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])
        ->callTableAction('create', data: [
            'code' => 'FC',
            'name' => 'Food Court',
            'is_active' => true,
            'supervisors' => $mallBStaff->id,
        ])
        ->assertHasTableActionErrors(['supervisors']);

    expect(Area::where('asset_id', $this->asset->id)->where('code', 'FC')->exists())->toBeFalse();
});

it('offers the picker only the property’s own staff', function () {
    $mine = makeUser('operations', [$this->asset->id]);
    $theirs = makeUser('operations', [makeAsset(['code' => 'ZTC'])->id]);

    $component = Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])->mountTableAction('create');

    $field = $component->instance()->getMountedTableActionForm()->getComponent('supervisors');
    $offered = array_keys($field->getOptions());

    expect($offered)->toContain($mine->id)
        ->and($offered)->not->toContain($theirs->id);
});

it('edits a zone’s supervisors from the property page, and refuses a smuggled one there too', function () {
    // The EDIT door had no test at all — deleting its `->after()` or its `->databaseTransaction()`
    // left the file green. Found by review.
    $mine = makeUser('operations', [$this->asset->id]);
    $mallBStaff = makeUser('operations', [makeAsset(['code' => 'ZTD'])->id]);
    $zone = Area::create(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court', 'is_active' => true]);

    $component = Livewire::test(AssetAreasRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ]);

    // The control: a legitimate edit attaches the property's own staff member.
    $component->callTableAction('edit', $zone, data: ['name' => 'Food Hall', 'supervisors' => [$mine->id]])
        ->assertHasNoTableActionErrors();

    expect($zone->fresh()->name)->toBe('Food Hall')
        ->and($zone->supervisors()->pluck('users.id')->all())->toBe([$mine->id]);

    // The refusal: a scalar smuggle is refused at validation (`MultiValueFieldIsAnArray`), so the
    // rename in the same payload never lands either.
    $component->callTableAction('edit', $zone, data: ['name' => 'Renamed', 'supervisors' => $mallBStaff->id])
        ->assertHasTableActionErrors(['supervisors']);

    expect($zone->fresh()->name)->toBe('Food Hall')
        ->and($zone->supervisors()->pluck('users.id')->all())->toBe([$mine->id]);
});

it('refuses the scalar on the REGISTER too, and leaves no orphan', function () {
    // Measured by review before this case existed: the register's Create page, with a scalar
    // smuggle, answered 403 with the pivot stripped AND THE ZONE LEFT ON DISK — `CreateRecord`
    // defaults `$hasDatabaseTransactions` to the panel's setting and no panel opts in, so the
    // guard's own docblock ("the write is rejected") was false on the page it was written for,
    // and the tab had just been made STRICTER than the register it claimed to mirror. Both pages
    // declare the transaction now; the `array` rule then closed the payload shape itself, so the
    // observable outcome on this door is a validation error and nothing written.
    $mallBStaff = makeUser('operations', [makeAsset(['code' => 'ZTE'])->id]);
    Filament::setTenant($this->asset);

    Livewire::test(CreateArea::class)
        ->fillForm(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court', 'is_active' => true])
        ->set('data.supervisors', $mallBStaff->id)
        ->call('create')
        ->assertHasFormErrors(['supervisors']);

    expect(Area::where('asset_id', $this->asset->id)->where('code', 'FC')->exists())->toBeFalse();

    Filament::setTenant(null, isQuiet: true);
});

it('refuses the scalar on the REGISTER\'s Edit page, and the rename beside it never lands', function () {
    $mine = makeUser('operations', [$this->asset->id]);
    $mallBStaff = makeUser('operations', [makeAsset(['code' => 'ZTF'])->id]);
    $zone = Area::create(['asset_id' => $this->asset->id, 'code' => 'FC', 'name' => 'Food Court', 'is_active' => true]);
    $zone->supervisors()->sync([$mine->id]);
    Filament::setTenant($this->asset);

    Livewire::test(EditArea::class, ['record' => $zone->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->set('data.supervisors', $mallBStaff->id)
        ->call('save')
        ->assertHasFormErrors(['supervisors']);

    expect($zone->fresh()->name)->toBe('Food Court')
        ->and($zone->supervisors()->pluck('users.id')->all())->toBe([$mine->id]);

    Filament::setTenant(null, isQuiet: true);
});

it('is read by the write-surface gate on BOTH doors, so the parity check can see the field', function () {
    // The gate compares what the tab asks against what the register asks, and with the two now
    // equal its answer is EMPTY — the same answer it gives when the field has dropped off BOTH
    // sides, because nothing is missing from nothing. So the field this change is about is pinned
    // on both doors: a manager rewritten onto a picker the reader does not list would otherwise
    // read as parity. (Removing `EntitySelect` from the reader's list is NOT that mutation — its
    // `Select` entry matches the suffix — which is why this pins the field and not the reader.)
    $manager = 'app/Filament/Admin/RelationManagers/AssetAreasRelationManager.php';
    $form = 'app/Filament/Admin/Resources/Areas/Schemas/AreaForm.php';

    expect(WriteSurfaces::fieldsAskedIn($manager))->toContain('supervisors')
        ->and(WriteSurfaces::fieldsAskedIn($form))->toContain('supervisors')
        ->and(WriteSurfaces::fieldsMissingFrom(WriteSurfaces::fieldsAskedIn($form), WriteSurfaces::fieldsAskedIn($manager), 'asset_id'))->toBe([]);
});
