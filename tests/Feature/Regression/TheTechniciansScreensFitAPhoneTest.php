<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Table;

/**
 * The technician's two screens have to be usable in one hand.
 *
 * ## Why this is a requirement and not polish
 *
 * The operator DECLINED a technician mobile app (O3): technicians use the admin panel. That makes
 * the `technician` role's phone experience part of the product rather than a nicety — and the two
 * screens they live in were the two widest in the panel. Work orders showed **17 columns** by
 * default and tenant requests **15**, which on a 375px screen is a horizontal scroll through
 * information a technician standing at a chiller does not need: which property it belongs to, who
 * bears the cost, whether it is over the not-to-exceed limit, its PM-compliance state.
 *
 * ## `visibleFrom`, not `isToggledHiddenByDefault`
 *
 * Hiding a column by default would take it away from the SUPERVISOR at a desk, who is the other
 * user of the same list and the one those columns were added for. `Column::visibleFrom('md')`
 * renders a responsive class, so the column is absent below the breakpoint and unchanged above it.
 * Desktop keeps all seventeen; a phone gets six.
 *
 * ## What "six" is
 *
 * What, where, how urgent, by when — plus the reference to quote and the status to change. Anything
 * that answers a question a technician cannot act on from the floor is desk work.
 *
 * **This test cannot tell you it LOOKS right.** It pins which columns survive the breakpoint and
 * that the responsive class reaches the DOM; whether six columns and the row actions actually fit
 * is a question only a browser at 375px answers. `tests/e2e/24-phone-technician.spec.js` is that
 * check, and it is deliberately not part of any automated run here.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The columns a phone actually renders: shown by default AND not held back to a breakpoint. */
function phoneColumns(string $resource): array
{
    $page = $resource::getPages()['index']->getPage();
    $table = $resource::table(Table::make(new $page));

    return array_values(array_map(
        fn ($column): string => $column->getName(),
        array_filter(
            $table->getColumns(),
            fn ($column): bool => ! $column->isToggledHiddenByDefault() && blank($column->getVisibleFrom()),
        ),
    ));
}

/** Every column the desktop shows by default, breakpoint or not. */
function desktopColumns(string $resource): array
{
    $page = $resource::getPages()['index']->getPage();
    $table = $resource::table(Table::make(new $page));

    return array_values(array_map(
        fn ($column): string => $column->getName(),
        array_filter($table->getColumns(), fn ($column): bool => ! $column->isToggledHiddenByDefault()),
    ));
}

it('shows a work order in six columns on a phone and seventeen at a desk', function () {
    $phone = phoneColumns(FacilityWorkOrderResource::class);
    $desktop = desktopColumns(FacilityWorkOrderResource::class);

    // What a technician standing at the equipment needs: what it is, where, how urgent, by when.
    expect($phone)->toEqualCanonicalizing([
        'reference', 'title', 'area.name', 'priority', 'target_resolution_at', 'status',
    ]);

    // And the supervisor at a desk loses nothing — that is the whole point of using a breakpoint
    // rather than hiding the columns by default.
    expect(count($desktop))->toBeGreaterThan(15);
    expect($desktop)->toContain('cost_bearer');
    expect($desktop)->toContain('pm_compliance');
});

it('shows a tenant request in six columns on a phone', function () {
    $phone = phoneColumns(TenantRequestResource::class);

    expect($phone)->toEqualCanonicalizing([
        'reference', 'title', 'unit.code', 'priority', 'status', 'target_resolution_at',
    ]);

    expect(count(desktopColumns(TenantRequestResource::class)))->toBeGreaterThan(13);
});

it('carries the breakpoint into the DOM', function () {
    // The column decides; the class is what the BROWSER acts on. Filament renders `visibleFrom`
    // as a `{breakpoint}:fi-visible` class on the cell — asserting the column's own state would
    // pass whether or not that ever reached the page.
    $html = $this->get("/admin/{$this->asset->code}/facility-work-orders")->getContent();

    expect($html)->toContain('md:fi-visible');
});

it('never narrows a column the phone set depends on', function () {
    // The failure this guards is subtle: somebody adds `visibleFrom('md')` to one more column to
    // tidy the desktop view and takes it off the phone too, leaving a technician a list they
    // cannot identify a job from. The six are named above; this asserts none of them carries a
    // breakpoint at all.
    foreach ([FacilityWorkOrderResource::class, TenantRequestResource::class] as $resource) {
        $page = $resource::getPages()['index']->getPage();
        $table = $resource::table(Table::make(new $page));

        foreach (['reference', 'title', 'priority', 'status'] as $essential) {
            $column = $table->getColumn($essential);

            expect($column)->not->toBeNull(class_basename($resource)." lost its `{$essential}` column.");
            // `toBeBlank()` is not a Pest expectation — asserting on the boolean keeps the
            // message, which is the part that tells the next person what they broke.
            expect(blank($column->getVisibleFrom()))->toBeTrue(
                class_basename($resource).": `{$essential}` is held back to a breakpoint, so a phone "
                .'cannot identify the row.');
        }
    }
});
