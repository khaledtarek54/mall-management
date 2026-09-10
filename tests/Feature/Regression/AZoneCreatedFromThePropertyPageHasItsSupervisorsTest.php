<?php

use App\Filament\Admin\RelationManagers\AssetAreasRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Area;
use Database\Seeders\RolesPermissionsSeeder;
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

it('strips a smuggled supervisor that slips past validation, and leaves no orphan', function () {
    // Filament derives `Rule::in` for an ARRAY payload. A SCALAR — `'supervisors' => 5` rather
    // than `[5]` — passes validation, the row commits, the relationship syncs, and only then does
    // `->after()` run `assertSupervisorsInScope()`, which strips the pivot and 403s. Found by
    // review with a query log, against a comment claiming this path could not reach the guard.
    //
    // And that 403 used to leave an EMPTY ZONE behind: `CreateAction` on a relation manager is not
    // transactional unless told, where the register's `CreateRecord` page always is. So the tab
    // now declares `->databaseTransaction()`, and the outcome is nothing written at all.
    $other = makeAsset(['code' => 'ZTB']);
    $mallBStaff = makeUser('operations', [$other->id]);

    // Livewire's harness records the guard's `abort(403)` as the RESPONSE status rather than
    // rethrowing it — the first cut expected a thrown HttpException and read "not thrown" as
    // "guard did not fire", while the zone's absence said it had.
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
        ->assertForbidden();

    // No pivot, and — because the action is transactional — no orphaned zone either.
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
