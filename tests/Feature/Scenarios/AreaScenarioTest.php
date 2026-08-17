<?php

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Areas\Pages\ListAreas;
use App\Models\Area;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Facility zones (module 30): a zone stands in exactly one property, its code is
 * unique per property, it carries a many-to-many set of supervisor Users, and it
 * is gated by the `areas.*` permissions + the property-isolation write guard.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'ARA']);
});

function makeArea(int $assetId, array $attrs = []): Area
{
    return Area::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'GF',
        'name' => 'Ground Floor',
    ], $attrs));
}

/* ---- create + defaults -------------------------------------------------- */

it('creates a facility zone with a name and code', function () {
    $area = makeArea($this->asset->id, ['code' => 'FC', 'name' => 'Food Court']);

    expect($area->exists)->toBeTrue();
    expect($area->fresh()->name)->toBe('Food Court');
});

it('defaults is_active to true so a NOT-NULL column never receives null', function () {
    // Both the DB default and the model $attributes default guard the blank-toggle path.
    expect(makeArea($this->asset->id)->is_active)->toBeTrue();
});

/* ---- per-property code uniqueness --------------------------------------- */

it('requires the code to be unique within a property', function () {
    makeArea($this->asset->id, ['code' => 'GF']);

    expect(fn () => makeArea($this->asset->id, ['code' => 'GF']))
        ->toThrow(QueryException::class);
});

it('lets two properties reuse the same code', function () {
    // Per-property uniqueness is the point: without it every mall would need a
    // prefix baked into each code, which is what asset_id is for.
    $other = makeAsset(['code' => 'ARB']);

    makeArea($this->asset->id, ['code' => 'GF']);
    $twin = makeArea($other->id, ['code' => 'GF']);

    expect($twin->exists)->toBeTrue();
    expect(Area::where('code', 'GF')->count())->toBe(2);
});

/* ---- supervisors (many-to-many) ----------------------------------------- */

it('assigns supervisors and keeps the pivot unique', function () {
    $area = makeArea($this->asset->id);
    $sup1 = makeUser('operations', [$this->asset->id]);
    $sup2 = makeUser('coordinator', [$this->asset->id]);

    $area->supervisors()->sync([$sup1->id, $sup2->id]);

    expect($area->fresh()->supervisors()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$sup1->id, $sup2->id]);

    // syncWithoutDetaching a duplicate must not create a second pivot row
    // (unique(area_id, user_id)) — and it must not throw.
    $area->supervisors()->syncWithoutDetaching([$sup1->id]);
    expect($area->fresh()->supervisors()->count())->toBe(2);

    // A supervisor may cover many areas.
    $second = makeArea($this->asset->id, ['code' => 'PKG', 'name' => 'Parking']);
    $second->supervisors()->attach($sup1->id);
    expect(Area::whereHas('supervisors', fn ($q) => $q->whereKey($sup1->id))->count())->toBe(2);
});

/* ---- RBAC --------------------------------------------------------------- */

it('gates the register on areas permissions', function () {
    // operations + coordinator manage zones.
    $this->actingAs(makeUser('operations'));
    expect(AreaResource::canViewAny())->toBeTrue();
    expect(AreaResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('coordinator'));
    expect(AreaResource::canViewAny())->toBeTrue();
    expect(AreaResource::canCreate())->toBeTrue();

    // viewer sees zones (blanket .view) but cannot create them.
    $this->actingAs(makeUser('viewer'));
    expect(AreaResource::canViewAny())->toBeTrue();
    expect(AreaResource::canCreate())->toBeFalse();

    // leasing has no business in the facility layout at all.
    $this->actingAs(makeUser('leasing'));
    expect(AreaResource::canViewAny())->toBeFalse();
    expect(AreaResource::canCreate())->toBeFalse();
});

it('reserves delete for super_admin only', function () {
    $area = makeArea($this->asset->id);

    $this->actingAs(makeUser('operations'));
    expect(AreaResource::canDelete($area))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(AreaResource::canDelete($area))->toBeTrue();
});

/* ---- property scoping (read + write guard) ------------------------------ */

it('scopes the register to the current property', function () {
    $other = makeAsset(['code' => 'ARC']);
    makeArea($this->asset->id, ['code' => 'A-GF']);
    makeArea($other->id, ['code' => 'B-GF']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($this->asset, function () {
        expect(scopedResourceQuery(AreaResource::class)->pluck('code')->all())
            ->toContain('A-GF')->not->toContain('B-GF');
    });
});

it('rejects an out-of-scope asset_id on write', function () {
    // A restricted user (assigned only to their mall) cannot tamper the property
    // Select to write into another mall's zone list.
    $other = makeAsset(['code' => 'ARD']);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    AreaResource::assertAssetInScope($this->asset->id); // in scope — no throw

    expect(fn () => AreaResource::assertAssetInScope($other->id))
        ->toThrow(HttpException::class);
});

/* ---- the table renders with rows ---------------------------------------- */

it('renders the areas table with rows (including the supervisors column)', function () {
    // Cross-property read scoping is proven deterministically above via
    // scopedResourceQuery(); here we assert the table actually paints its rows —
    // code, name, supervisor list — without a render error.
    $sup = makeUser('operations', [$this->asset->id]);
    $ground = makeArea($this->asset->id, ['code' => 'GF', 'name' => 'Ground Floor']);
    $ground->supervisors()->attach($sup->id);
    $parking = makeArea($this->asset->id, ['code' => 'PKG', 'name' => 'Parking']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListAreas::class)
        ->assertCanSeeTableRecords([$ground, $parking])
        ->assertSee('Ground Floor')
        ->assertSee($sup->name);
});
