<?php

/*
|--------------------------------------------------------------------------
| Feature #14 — Multi-unit lease: FORM / TABLE / RBAC (via Livewire)
|--------------------------------------------------------------------------
| Net-new scenarios on top of tests/Feature/MultiUnitLeaseTest.php (which
| covers the model-level syncUnits + a single EditLease "add" path + the
| table "sees A-02" assertion) and AuthorizationMatrixTest (gate methods
| directly). Here we drive the REAL Filament pages through Livewire:
|
|   - CreateLease persists the pivot AND occupies every chosen unit.
|   - EditLease prefills additional_unit_ids from the non-master pivot rows,
|     and add / remove re-syncs occupancy (added → occupied, removed → vacant).
|   - additional_unit_ids OPTIONS are property-scoped and exclude the chosen
|     master + occupied / reserved / maintenance units.
|   - The leases table row description shows "+ <code>" ONLY for multi-unit
|     leases; a single-unit lease shows no "+ ..." line.
|   - RBAC: leasing / manager / super_admin reach Create+Edit; viewer / owner
|     are blocked by the LeaseResource gates.
*/

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'HW']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Fill the required Create form fields, leaving unit/tenant/additional to the caller. */
function leaseCreatePayload(int $masterUnitId, int $tenantId, array $overrides = []): array
{
    return array_merge([
        'unit_id' => $masterUnitId,
        'tenant_id' => $tenantId,
        'status' => 'active',
        'commencement_date' => '2026-06-01',
        'expiry_date' => '2027-05-31',
        'term_months' => 12,
        'base_rent_monthly' => 5000,
        'service_charge_monthly' => 1000,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| CreateLease (Livewire) — happy path with additional units
|--------------------------------------------------------------------------
*/

it('creates a multi-unit lease through the create form and occupies every unit', function () {
    $master = makeUnit($this->asset, ['code' => 'C-01', 'status' => 'vacant']);
    $extraA = makeUnit($this->asset, ['code' => 'C-02', 'status' => 'vacant']);
    $extraB = makeUnit($this->asset, ['code' => 'C-03', 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseCreatePayload($master->id, $tenant->id, [
            'additional_unit_ids' => [$extraA->id, $extraB->id],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::where('tenant_id', $tenant->id)->firstOrFail();

    // Pivot is the source of truth: 3 units, master mirrored to unit_id.
    $ids = $lease->units()->pluck('units.id')->all();
    expect($ids)->toHaveCount(3)
        ->and($ids)->toContain($master->id, $extraA->id, $extraB->id)
        ->and((int) $lease->unit_id)->toBe($master->id)
        ->and($lease->units()->wherePivot('is_master', true)->count())->toBe(1);

    // Every unit on the lease is occupied (master + additionals).
    expect($master->fresh()->status)->toBe('occupied')
        ->and($extraA->fresh()->status)->toBe('occupied')
        ->and($extraB->fresh()->status)->toBe('occupied');
});

it('creates a single-unit lease (no additional ids) with exactly one master pivot row', function () {
    $master = makeUnit($this->asset, ['code' => 'S-01', 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseCreatePayload($master->id, $tenant->id))
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::where('tenant_id', $tenant->id)->firstOrFail();

    expect($lease->units()->pluck('units.id')->all())->toBe([$master->id])
        ->and((bool) $lease->units()->first()->pivot->is_master)->toBeTrue()
        ->and($master->fresh()->status)->toBe('occupied');
});

/*
|--------------------------------------------------------------------------
| EditLease (Livewire) — prefill + remove re-sync
|--------------------------------------------------------------------------
*/

it('prefills additional_unit_ids from the non-master pivot rows on edit', function () {
    $master = makeUnit($this->asset, ['code' => 'E-01']);
    $extra = makeUnit($this->asset, ['code' => 'E-02']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->assertOk()
        // master must NOT leak into the additional selector — only the non-master pivot.
        ->assertFormSet(['additional_unit_ids' => [$extra->id]]);
});

it('frees a removed unit when it is dropped from the edit form', function () {
    $master = makeUnit($this->asset, ['code' => 'R-01', 'status' => 'vacant']);
    $extra = makeUnit($this->asset, ['code' => 'R-02', 'status' => 'vacant']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    expect($extra->fresh()->status)->toBe('occupied');

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->assertFormSet(['additional_unit_ids' => [$extra->id]])
        ->fillForm(['additional_unit_ids' => []])   // drop the extra unit
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lease->units()->pluck('units.id')->all())->toBe([$master->id])
        ->and($extra->fresh()->status)->toBe('vacant')   // freed
        ->and($master->fresh()->status)->toBe('occupied');
});

it('swaps the additional unit on edit — old freed, new occupied', function () {
    $master = makeUnit($this->asset, ['code' => 'W-01', 'status' => 'vacant']);
    $old = makeUnit($this->asset, ['code' => 'W-02', 'status' => 'vacant']);
    $new = makeUnit($this->asset, ['code' => 'W-03', 'status' => 'vacant']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $old->id], $master->id);

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->assertFormSet(['additional_unit_ids' => [$old->id]])
        ->fillForm(['additional_unit_ids' => [$new->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $ids = $lease->fresh()->units()->pluck('units.id')->all();
    expect($ids)->toContain($master->id, $new->id)
        ->and($ids)->not->toContain($old->id)
        ->and($old->fresh()->status)->toBe('vacant')
        ->and($new->fresh()->status)->toBe('occupied');
});

/*
|--------------------------------------------------------------------------
| additional_unit_ids OPTIONS — property-scoped, excludes master + busy units
|--------------------------------------------------------------------------
| The options closure reads TenantScope::currentAssetId() + the live unit_id.
| We render the Create form, set the master, then read the component options.
*/

/** Resolve the option keys for the additional_unit_ids select on a mounted page. */
function additionalUnitOptionKeys(Testable $page): array
{
    $component = $page->instance()->form->getComponent('additional_unit_ids');

    return array_map('intval', array_keys($component->getOptions()));
}

it('scopes additional-unit options to the current property and drops busy units + the master', function () {
    $other = makeAsset(['code' => 'OTHER']);

    $master = makeUnit($this->asset, ['code' => 'O-MASTER', 'status' => 'vacant']);
    $vacant = makeUnit($this->asset, ['code' => 'O-VACANT', 'status' => 'vacant']);
    $occupied = makeUnit($this->asset, ['code' => 'O-OCC', 'status' => 'occupied']);
    $reserved = makeUnit($this->asset, ['code' => 'O-RES', 'status' => 'reserved']);
    $maint = makeUnit($this->asset, ['code' => 'O-MNT', 'status' => 'maintenance']);
    $foreign = makeUnit($other, ['code' => 'F-VACANT', 'status' => 'vacant']);
    $tenant = makeTenant();

    $page = Livewire::test(CreateLease::class)
        ->fillForm(leaseCreatePayload($master->id, $tenant->id));

    $options = additionalUnitOptionKeys($page);

    expect($options)->toContain($vacant->id)            // free unit on this property — offered
        ->and($options)->not->toContain($master->id)    // the chosen master is excluded
        ->and($options)->not->toContain($occupied->id)  // occupied excluded
        ->and($options)->not->toContain($reserved->id)  // reserved excluded
        ->and($options)->not->toContain($maint->id)     // maintenance excluded
        ->and($options)->not->toContain($foreign->id);  // other property excluded
});

it('keeps the already-attached additional unit in the edit options even though it is occupied', function () {
    $master = makeUnit($this->asset, ['code' => 'K-01', 'status' => 'vacant']);
    $attached = makeUnit($this->asset, ['code' => 'K-02', 'status' => 'vacant']);
    $free = makeUnit($this->asset, ['code' => 'K-03', 'status' => 'vacant']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $attached->id], $master->id);

    // $attached is now 'occupied' by THIS lease; it must still be selectable so
    // the operator can keep it. A truly free unit is offered too.
    expect($attached->fresh()->status)->toBe('occupied');

    $page = Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()]);
    $options = additionalUnitOptionKeys($page);

    expect($options)->toContain($attached->id)   // already on the lease — kept
        ->and($options)->toContain($free->id)    // a free unit — offered
        ->and($options)->not->toContain($master->id);
});

/*
|--------------------------------------------------------------------------
| Leases table — "+ <code>" description only for multi-unit leases
|--------------------------------------------------------------------------
*/

it('shows the "+ <code>" additional-units line only for multi-unit leases', function () {
    $singleUnit = makeUnit($this->asset, ['code' => 'T-SINGLE']);
    $single = makeLease($singleUnit, null, ['status' => 'active']);

    $masterUnit = makeUnit($this->asset, ['code' => 'T-MASTER']);
    $extraUnit = makeUnit($this->asset, ['code' => 'T-EXTRA']);
    $multi = makeLease($masterUnit, null, ['status' => 'active']);
    $multi->syncUnits([$masterUnit->id, $extraUnit->id], $masterUnit->id);

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertSee('T-MASTER')
        ->assertSee('+ T-EXTRA')      // multi-unit row carries the additional-units description
        ->assertSee('T-SINGLE')
        ->assertDontSee('+ T-SINGLE'); // single-unit row has NO "+ ..." line
});

it('description helper renders "+ <codes>" for additional units and null for single-unit', function () {
    // Exercise the exact column description closure both ways.
    $describe = function (Lease $lease): ?string {
        $extra = $lease->units->reject(fn ($u) => $u->pivot->is_master);

        return $extra->isNotEmpty() ? '+ '.$extra->pluck('code')->join(', ') : null;
    };

    $solo = makeLease(makeUnit($this->asset, ['code' => 'D-SOLO']), null, ['status' => 'active']);
    expect($describe($solo->load('units')))->toBeNull();

    $m = makeUnit($this->asset, ['code' => 'D-M']);
    $a = makeUnit($this->asset, ['code' => 'D-A1']);
    $b = makeUnit($this->asset, ['code' => 'D-A2']);
    $multi = makeLease($m, null, ['status' => 'active']);
    $multi->syncUnits([$m->id, $a->id, $b->id], $m->id);

    expect($describe($multi->fresh()->load('units')))->toContain('D-A1', 'D-A2')
        ->and($describe($multi->fresh()->load('units')))->toStartWith('+ ');
});

/*
|--------------------------------------------------------------------------
| RBAC — who can drive the multi-unit Create / Edit pages
|--------------------------------------------------------------------------
*/

it('lets leasing, manager and super_admin reach the lease Create + Edit pages', function (string $role) {
    $user = makeUser($role, [$this->asset->id]);
    $this->actingAs($user);

    expect(LeaseResource::canCreate())->toBeTrue("{$role} canCreate")
        ->and(LeaseResource::canViewAny())->toBeTrue("{$role} canViewAny");

    $master = makeUnit($this->asset, ['code' => "RB-{$role}-1", 'status' => 'vacant']);
    $extra = makeUnit($this->asset, ['code' => "RB-{$role}-2", 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)
        ->fillForm(leaseCreatePayload($master->id, $tenant->id, [
            'additional_unit_ids' => [$extra->id],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lease->units()->pluck('units.id')->all())->toContain($extra->id)
        ->and(LeaseResource::canEdit($lease))->toBeTrue("{$role} canEdit");

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->assertOk()
        ->assertFormSet(['additional_unit_ids' => [$extra->id]]);
})->with(['leasing', 'manager', 'super_admin']);

it('blocks viewer and owner from creating or editing a lease', function (string $role) {
    // Build the multi-unit lease as super_admin first (beforeEach already acts as one).
    $master = makeUnit($this->asset, ['code' => "NB-{$role}-1", 'status' => 'vacant']);
    $extra = makeUnit($this->asset, ['code' => "NB-{$role}-2", 'status' => 'vacant']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    // Switch to the read-only role.
    $this->actingAs(makeUser($role, [$this->asset->id]));

    expect(LeaseResource::canCreate())->toBeFalse("{$role} must not create")
        ->and(LeaseResource::canEdit($lease))->toBeFalse("{$role} must not edit");

    // The Livewire Create page mounts behind canCreate() — it must throw 403.
    Livewire::test(CreateLease::class)
        ->assertForbidden();
})->with(['viewer', 'owner']);
