<?php

use App\Filament\Admin\RelationManagers\AssetRentableItemsRelationManager;
use App\Filament\Admin\RelationManagers\AssetUnitsRelationManager;
use App\Filament\Admin\RelationManagers\TenantViolationsRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Asset;
use App\Models\RentableItem;
use App\Models\Violation;
use App\Support\Filament\PropertyLink;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * **A LINK OFF THE PROPERTY PAGE OPENS IN THAT PROPERTY, NOT IN WHICHEVER MALL THE SWITCHER
 * HAPPENS TO BE ON.**
 *
 * Reported from the panel: `/admin/VP/units/13/edit`, reached from the property page, **404**.
 *
 * `AssetResource` is one of the six resources that deliberately sit ABOVE the per-property context
 * (`$isScopedToTenant = false`) — it lists the operator's WHOLE portfolio, because managing the
 * malls themselves is what it is for and a newly created mall is never the active one. Its Units,
 * Rentable items and Staff tabs therefore show the records of the property being LOOKED AT, which
 * is very often not the property SELECTED in the switcher.
 *
 * `Resource::getUrl()` fills the `{tenant}` segment from `Filament::getTenant()` — the switcher —
 * so a link built without one names mall A while pointing at mall B's record. Every one of those
 * target resources is `ScopesToProperty`, and Filament resolves a route-bound record through the
 * resource's own scoped query, so the destination is a **404**: not a refusal the operator can act
 * on, but a dead end from a row they are looking at.
 *
 * `AssetStaffRelationManager` had this right from the day it was written, in a comment that states
 * the whole rule — *"The TENANT is passed explicitly … a relation manager already knows the
 * property it belongs to"*. The two beside it did not, and the rule being written down next door is
 * exactly why this is gated by `ALinkOffTheOwnersPageNamesItsOwnPropertyConformanceTest` rather
 * than fixed as two more careful call sites.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));

    // The mall the operator is LOOKING AT, and the unrelated one the switcher is left on.
    $this->looking = makeAsset(['code' => 'PA', 'name' => 'Plaza Annex']);
    $this->selected = makeAsset(['code' => 'VP', 'name' => 'Val Plaza']);
});

/**
 * The tab as the property page mounts it, with the switcher left on another mall.
 *
 * The assertions run INSIDE the tenant scope on purpose: `getUrl()` throws
 * `UrlGenerationException` with no tenant set, so a check made after `asTenant()` has restored the
 * previous tenant tests a different request than the one an operator makes.
 */
function onPropertyTab(string $manager, Asset $looking, Asset $selected, callable $assert): void
{
    asTenant($selected, fn () => $assert(Livewire::test($manager, [
        'ownerRecord' => $looking,
        'pageClass' => EditAsset::class,
    ])));
}

it('links a unit at the property whose page it was clicked from', function () {
    $unit = makeUnit($this->looking, ['code' => 'A-05']);

    onPropertyTab(
        AssetUnitsRelationManager::class,
        $this->looking,
        $this->selected,
        fn ($tab) => $tab->assertTableActionHasUrl('edit', url("/admin/PA/units/{$unit->id}/edit"), $unit),
    );
});

it('opens a unit in that property when the ROW is clicked', function () {
    // The button and the row are two affordances and only one of them is a declared action: the
    // row's href comes from `RowClickTarget`, which resolves the table's own `edit` action. It
    // therefore inherits the fix — and would have inherited the bug just as silently, which is why
    // it is asserted rather than assumed.
    $unit = makeUnit($this->looking, ['code' => 'A-05']);

    onPropertyTab(
        AssetUnitsRelationManager::class,
        $this->looking,
        $this->selected,
        function ($tab) use ($unit) {
            $url = $tab->instance()->getTable()->getRecordUrl($unit);

            expect($url)->toContain("/admin/PA/units/{$unit->id}/edit")
                ->and($url)->not->toContain('/admin/VP/');
        },
    );
});

it('links a rentable item at the property whose page it was clicked from', function () {
    $item = RentableItem::create([
        'asset_id' => $this->looking->id,
        'code' => 'BAY-1',
        'name' => 'Bay 1',
        'type' => 'parking',
        'status' => 'available',
    ]);

    onPropertyTab(
        AssetRentableItemsRelationManager::class,
        $this->looking,
        $this->selected,
        fn ($tab) => $tab->assertTableActionHasUrl('open', url("/admin/PA/rentable-items/{$item->id}/edit"), $item),
    );
});

it('is a dead end and not a refusal when a link names the wrong property', function () {
    // The CONTROL for all three: this is what the operator actually hit, so it is worth pinning
    // that the wrong-tenant URL really is unreachable — a destination that happened to work would
    // make the fix cosmetic, and pairing it with the one that must SUCCEED is what stops a
    // resource scoped down to nothing reading as a pass.
    $unit = makeUnit($this->looking, ['code' => 'A-06']);

    asTenant($this->selected, function () use ($unit) {
        $this->get("/admin/VP/units/{$unit->id}/edit")->assertNotFound();
        $this->get("/admin/PA/units/{$unit->id}/edit")->assertSuccessful();
    });
});

/**
 * **THE SAME DEFECT THROUGH A DIFFERENT DOOR — and this one is not about a portfolio-wide OWNER.**
 *
 * A tenant's page IS narrowed to the selected mall (`TenantResource` scopes through `leases.unit`),
 * so the tenant on screen always trades here. Its TABS are another matter, and they are not all
 * alike — which is the distinction the first version of this test got wrong.
 *
 * The violations and sales-declaration tabs are scoped by **nothing at all**: a tenant's compliance
 * history is listed wherever they trade. Those rows really do span malls, so the property is a fact
 * about each ROW and `App\Support\Filament\PropertyLink` is what reads it — the resolver
 * `NotificationLink` already had for building deep links from a queue worker, where there is
 * likewise no switcher to read.
 *
 * The invoices and requests tabs narrow with `TenantScope::visibleAssetIds()`, and that method
 * answers the **SELECTED** property for any real tenant, super_admin included — so no away row can
 * reach those screens. **This test is written against a VIOLATION for exactly that reason.** Its
 * first version drove the invoices tab and handed the action a record the tab's own query can never
 * return, which is green whether the fix is there or not: the reachable-inputs trap, found in
 * review rather than by the suite.
 */
it('opens a tenant-tab row in the property that row belongs to', function () {
    // One retailer, two malls — the whole precondition. With a single-mall tenant the switcher and
    // the row agree and the bug is invisible, which is why it survived.
    $tenant = makeTenant();
    makeLease(makeUnit($this->selected), $tenant);

    $away = Violation::create([
        'asset_id' => $this->looking->id,
        'tenant_id' => $tenant->id,
        'description' => 'Shutter left open after trading hours',
        'violation_date' => '2026-09-01',
        'status' => 'open',
    ]);

    asTenant($this->selected, function () use ($tenant, $away) {
        $tab = Livewire::test(TenantViolationsRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => EditTenant::class,
        ]);

        // The row must actually be ON the screen — assert it through the table's own query, or the
        // link assertion below is about a row nobody can click.
        expect($tab->instance()->getTable()->getRecords()->pluck('id')->all())->toContain($away->id);

        $tab->assertTableActionHasUrl('open', url("/admin/PA/violations/{$away->id}/edit"), $away);
    });
});

/**
 * **THE UNRESOLVABLE ROW — proved at the seam, because no tab can reach it.**
 *
 * `PropertyLink::to()` answers null when it cannot name a property, and each Open action hides
 * itself on that answer: a control that goes nowhere is worse than no control, which is the rule
 * `TenantSalesDeclarationsRelationManager` already states for its own header action.
 *
 * Nothing reaches that branch through the four tabs today, and it is recorded here as UNEXERCISED
 * rather than implied to be covered. `invoices.asset_id` really is nullable — the state
 * `atriom:audit-property-dimension` exists to find — but `TenantScope::visibleAssetIds()` answers
 * the SELECTED property even for a super_admin, so the invoices tab narrows with
 * `whereIn('asset_id', [id])` and `whereIn` never matches null: such a row is not on the screen to
 * be clicked. The other three resolve through NOT NULL columns. The guard is for the tab that has
 * not been written yet, and the seam is where it can honestly be tested.
 */
it('answers no link for a record with no property, and a link for one that has it', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit($this->looking), $tenant));

    asTenant($this->selected, function () use ($invoice) {
        // The control FIRST — a resolver that answered null for everything would satisfy the
        // refusal below and break every link in the panel.
        expect(PropertyLink::to(InvoiceResource::class, $invoice))
            ->toContain("/admin/PA/invoices/{$invoice->id}/edit");

        $invoice->forceFill(['asset_id' => null])->saveQuietly();

        expect(PropertyLink::to(InvoiceResource::class, $invoice->fresh()))->toBeNull();
    });
});
