<?php

use App\Filament\Admin\RelationManagers\AssetRentableItemsRelationManager;
use App\Filament\Admin\RelationManagers\AssetUnitsRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
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
 * **THE SAME DEFECT THROUGH A DIFFERENT DOOR — and the premise this test shipped with was WRONG.**
 *
 * The version committed in `eca4b178` drove a VIOLATION on the tenant page and asserted the link
 * named the row's own mall, under a docblock stating that *"the violations and sales-declaration
 * tabs are scoped by nothing at all… those rows really do span malls"*. That sentence described a
 * BUG and treated it as a design decision. It was the leak — an operator holding one mall was
 * reading another's compliance history and declared turnover — and those two tabs narrow now, like
 * the five siblings beside them. See `ARecordPagesTabsShowOneMallTest`.
 *
 * So no tenant tab can return a row from a mall the reader does not hold, and the cross-mall case
 * is **unreachable through the panel** — which is the stronger fix, not a weaker test. Proving
 * `PropertyLink` therefore belongs at the SEAM, where the question can still be asked honestly.
 *
 * `PropertyLink` stays on all four tabs and is now belt and braces on every one of them. That is
 * deliberate: the answer to *which mall is this row in* should not depend on a scoping decision
 * made in another file, and the alternative is an exemption list on the link gate naming the tabs
 * that happen to be narrow this week.
 */
it('names the row\'s own property, on a record the reader holds', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit($this->looking), $tenant));

    asTenant($this->selected, function () use ($invoice) {
        // The CONTROL first — a resolver answering null for everything would satisfy the refusal in
        // the test below and break every link in the panel.
        expect(PropertyLink::to(InvoiceResource::class, $invoice))
            ->toContain("/admin/PA/invoices/{$invoice->id}/edit")
            ->not->toContain('/admin/VP/');
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
