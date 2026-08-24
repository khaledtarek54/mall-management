<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\FacilityWorkOrder;
use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * A sidebar badge answers "is there anything for me here", and it must answer for THIS property.
 *
 * Five worklists gained a badge once the sidebar stopped being built five times per render: work
 * orders past their SLA, purchase requests awaiting approval, cheques maturing this week, overdue
 * supplier bills and open violations. They are cheap now — measured at +6 queries on the invoices
 * page for all five, where before the memo the same five would have cost +25, because every badge
 * ran once per sidebar build.
 *
 * Two properties they all have to hold, and both fail silently:
 *
 * **Scoped.** Every badge counts through `static::getEloquentQuery()`, so it inherits the resource's
 * own property isolation. A badge that counted portfolio-wide would tell an operator standing in
 * Mall A that three violations need them, and show them an empty list.
 *
 * **Null at zero.** A row of grey zeroes is the same information as no badges, at more cost. The
 * house rule everywhere else is `$count > 0 ? (string) $count : null`.
 *
 * `FacilityWorkOrder::scopeOverdue()` was added for this and is the query twin of `isOverdue()`,
 * written beside it — a predicate asked one way of a record and another way of a table is two
 * definitions of one fact, and they drift silently.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->mallA = makeAsset(['code' => 'BGA']);
    $this->mallB = makeAsset(['code' => 'BGB']);
    // The shared `correctiveOrder()` helper defaults `asset_id` from `test()->asset`; every call
    // below names its property explicitly, but the default is still evaluated.
    $this->asset = $this->mallA;
    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));
});

it('agrees with the record it counts', function () {
    Filament::setTenant($this->mallA, isQuiet: true);

    $unit = makeUnit($this->mallA);

    // One breached, one comfortably inside its target.
    correctiveOrder(['asset_id' => $this->mallA->id, 'unit_id' => $unit->id, 'status' => 'in_progress',
        'target_resolution_at' => now()->subDay()]);
    correctiveOrder(['asset_id' => $this->mallA->id, 'unit_id' => $unit->id, 'status' => 'in_progress',
        'target_resolution_at' => now()->addWeek()]);

    // The scope and the per-record method must answer identically — that is the whole reason the
    // scope was written beside `isOverdue()` rather than in the badge.
    $byScope = FacilityWorkOrder::query()->overdue()->count();
    $byRecord = FacilityWorkOrder::all()->filter->isOverdue()->count();

    expect($byScope)->toBe($byRecord);
    expect($byScope)->toBe(1);
    expect(FacilityWorkOrderResource::getNavigationBadge())->toBe('1');
});

it('counts only the property the operator is standing in', function () {
    $unitA = makeUnit($this->mallA);
    $unitB = makeUnit($this->mallB);

    correctiveOrder(['asset_id' => $this->mallA->id, 'unit_id' => $unitA->id, 'status' => 'in_progress',
        'target_resolution_at' => now()->subDay()]);
    correctiveOrder(['asset_id' => $this->mallB->id, 'unit_id' => $unitB->id, 'status' => 'in_progress',
        'target_resolution_at' => now()->subDay()]);
    correctiveOrder(['asset_id' => $this->mallB->id, 'unit_id' => $unitB->id, 'status' => 'in_progress',
        'target_resolution_at' => now()->subDays(3)]);

    Filament::setTenant($this->mallA, isQuiet: true);
    expect(FacilityWorkOrderResource::getNavigationBadge())->toBe('1');

    Filament::setTenant($this->mallB, isQuiet: true);
    expect(FacilityWorkOrderResource::getNavigationBadge())->toBe('2');
});

it('shows nothing rather than a zero', function () {
    Filament::setTenant($this->mallA, isQuiet: true);

    expect(FacilityWorkOrderResource::getNavigationBadge())->toBeNull();
    expect(ViolationResource::getNavigationBadge())->toBeNull();
});

it('gives every badge a colour and a tooltip in both languages', function () {
    Filament::setTenant($this->mallA, isQuiet: true);

    $badged = array_values(array_filter(
        Navigation::placed(),
        function (string $screen): bool {
            $class = new ReflectionClass($screen);

            return $class->hasMethod('getNavigationBadge')
                && $class->getMethod('getNavigationBadge')->getDeclaringClass()->getName() === $screen;
        },
    ));

    // The premise: a sweep that found no badges would pass every assertion below.
    expect(count($badged))->toBeGreaterThan(10);

    foreach ($badged as $screen) {
        expect($screen::getNavigationBadgeColor())->not->toBeNull(
            class_basename($screen).' has a badge with no colour — a count with no urgency reads as decoration.');
    }
});
