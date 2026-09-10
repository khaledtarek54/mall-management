<?php

use App\Filament\Admin\RelationManagers\TenantActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\TenantSalesDeclarationsRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Resources\Pages\EditRecord;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * **THE THREE SHAPES A TENANT PAGE LEAKS IN THAT ARE NOT A TABLE ROW.**
 *
 * `ARecordPagesTabsShowOneMallTest` sweeps the rows every at-risk tab returns. Three things on the
 * same page sit outside that sweep, and all three were found by an adversarial review of the fix
 * rather than by the fix's own tests:
 *
 *  1. **The BADGE.** `CountsItsRows` counts the plain relationship, so a narrowed tab hands back as
 *     a NUMBER exactly what its scope withholds as rows. That is covered by the sweep now; it is
 *     named here because it is the same family.
 *  2. **The TAB'S OWN VISIBILITY.** `TenantSalesDeclarations::canViewForRecord()` asked EVERY lease
 *     the tenant holds, portfolio-wide, so the tab appeared for a tenant whose only percentage-rent
 *     lease is in a mall this operator does not hold — a heading and an empty table, which that
 *     gate's own docblock says reads as *"they have not declared"* rather than *"there is nothing
 *     to declare"*.
 *  3. **THE ACTIVITY FEED, which is the real leak.** `ShowsItsChildrensActivity` widens a record's
 *     audit trail to its children's — for a tenant that includes its LEASES — and the subquery
 *     named only the owner. Measured before the fix: an operator holding one mall read
 *     `subject=lease#2`, a lease in a mall they do not hold. `CrossPropertyTabs` is structurally
 *     blind to it, because that manager's declared `$relationship` is `activitiesAsSubject`
 *     (`Activity` is not `#[PropertyOwned]`) while its real query is built in `getTableQuery()` —
 *     so it is asserted here by hand and recorded in that class as a known blind spot.
 *
 * CLAUDE.md already states this invariant for the activity LOG — *"a feed that spans every mall is
 * readable only by someone entitled to every mall"* — and this is the same feed through a different
 * door.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ensureAllPropertiesAsset();

    $this->mine = makeAsset(['code' => 'HSTA']);
    $this->theirs = makeAsset(['code' => 'HSTB']);

    $this->tenant = makeTenant();
    $this->leaseMine = makeLease(makeUnit($this->mine), $this->tenant);
    $this->leaseTheirs = makeLease(makeUnit($this->theirs), $this->tenant);

    // NO mall selected, deliberately — see ARecordPagesTabsShowOneMallTest for why selecting one
    // would make every assertion here pass on the SELECTION rather than on the ASSIGNMENT.
    $this->actingAs(makeUser('manager', [$this->mine->id]));
});

function tenantActivityRows($tenant): array
{
    return Livewire::test(TenantActivitiesRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditRecord::class,
    ])->instance()->getTable()->getRecords()
        ->map(fn ($row) => $row->subject_type.'#'.$row->subject_id)
        ->all();
}

it('does not show a lease from a mall the operator does not hold in the activity feed', function () {
    // Both leases are audited by being created. The CONTROL first: the operator's own mall's lease
    // must still be there, or a feed narrowed to nothing would satisfy the refusal below.
    $rows = tenantActivityRows($this->tenant);

    expect($rows)->toContain((new Lease)->getMorphClass().'#'.$this->leaseMine->id)
        ->and($rows)->not->toContain((new Lease)->getMorphClass().'#'.$this->leaseTheirs->id);
});

it('still shows the tenant\'s OWN activity, which belongs to no mall', function () {
    // The other direction: a tenant is `#[PortfolioShared]` and has no property of its own, so
    // narrowing must not reach the owner's own rows. Scoping the child branch by the CHILD's
    // declaration is what keeps these separable — a `TenantUser` or a `TenantDocument` is the
    // tenant's in every mall and is deliberately left alone.
    $this->tenant->update(['name' => 'Renamed In A Test']);

    expect(tenantActivityRows($this->tenant))
        ->toContain($this->tenant->getMorphClass().'#'.$this->tenant->id);
});

it('hides the sales tab when the only percentage-rent lease is in another mall', function () {
    $this->leaseTheirs->update(['has_percentage_rent' => true, 'percentage_rent_rate' => 5]);

    expect(TenantSalesDeclarationsRelationManager::canViewForRecord($this->tenant, EditTenant::class))
        ->toBeFalse();

    // The CONTROL — and it is the assertion that makes the one above mean anything, because a gate
    // that answered false for everyone would satisfy it while removing the tab from the panel.
    $this->leaseMine->update(['has_percentage_rent' => true, 'percentage_rent_rate' => 5]);

    expect(TenantSalesDeclarationsRelationManager::canViewForRecord($this->tenant, EditTenant::class))
        ->toBeTrue();
});
