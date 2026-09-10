<?php

namespace Tests\Support;

use App\Support\Attributes\PropertyItself;
use App\Support\PropertyIsolation;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use ReflectionClass;

/**
 * **WHICH RECORD-PAGE TABS CAN SHOW A ROW FROM A MALL THE OPERATOR DOES NOT HOLD.**
 *
 * The isolation suite drives every admin LIST (`ARestrictedOperatorSeesOneMallTest`) and
 * `PropertyIsolationConformanceTest` proves every RESOURCE is classified and scoped. Neither can
 * see a RELATION MANAGER: a tab builds its query from `$owner->relation()`, which no resource
 * `getEloquentQuery()` is involved in. Sixty-seven of them exist and the only isolation coverage
 * was three, hand-picked by a 2026-07 adversarial sweep — so the other sixty-four had never been
 * asked the question, and two of them answered it wrongly.
 *
 * A tab can leak when BOTH halves hold:
 *
 *  - the CHILD is `#[PropertyOwned]` — it belongs to one mall, so there is something to leak;
 *  - the OWNER is neither `#[PropertyOwned]` nor `#[PropertyItself]` — a portfolio-shared master
 *    (a `Tenant`, a `Vendor`) whose children are spread across malls.
 *
 * **Both exclusions are derived, and the second one matters most.** An owner that is itself
 * property-owned is only reachable inside the operator's own scope, so its children are too. And
 * `Asset` is `#[PropertyItself]`: its Units, Floors, Areas and Rentable-items tabs list the
 * property's OWN rows, and narrowing them to the SELECTED mall would empty the property page of
 * every mall except the active one — the opposite of what that page is for. Excluding those four by
 * name would have been a list; excluding them by attribute means the next such tab is right by
 * being what it is.
 */
class CrossPropertyTabs
{
    /**
     * **THE ONE SHAPE THIS DERIVATION CANNOT SEE, recorded rather than implied away.**
     *
     * A tab is classified by its declared `$relationship`. Two managers declare one their table
     * never queries — `BillingForecastRelationManager` says so in writing, and
     * `TenantActivitiesRelationManager` inherits `activitiesAsSubject` (child `Activity`, not
     * `#[PropertyOwned]`, so it is skipped here) while `ShowsItsChildrensActivity::getTableQuery()`
     * builds a different query that reaches `Lease` rows by `tenant_id`.
     *
     * That one WAS leaking — an operator holding one mall read another's lease history off it — and
     * it is fixed in the concern and asserted by
     * `ATenantsHistoryStopsAtTheMallYouHoldTest`, by hand, because nothing derived from
     * `$relationship` can reach it. Listed so the next reader knows the sweep's edge rather than
     * trusting its silence.
     */
    public const NOT_CLASSIFIABLE_BY_RELATIONSHIP = [
        \App\Filament\Admin\RelationManagers\TenantActivitiesRelationManager::class => 'getTableQuery() reaches Lease by tenant_id — covered by ATenantsHistoryStopsAtTheMallYouHoldTest',
        \App\Filament\Admin\RelationManagers\BillingForecastRelationManager::class => 'fed from Table::records(); the declared relation is never queried',
    ];

    /**
     * @return list<array{manager: class-string, owner: class-string, child: class-string, relation: string}>
     */
    public static function atRisk(): array
    {
        $out = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $owner = $resource::getModel();

            if ($owner === '' || PropertyIsolation::isOwned($owner) || static::isThePropertyItself($owner)) {
                continue;
            }

            foreach (static::managersOf($resource) as $manager) {
                $relation = static::relationshipOf($manager);
                $child = $relation === null ? null : static::relatedModel($owner, $relation);

                if ($child === null || ! PropertyIsolation::isOwned($child)) {
                    continue;
                }

                $out[] = [
                    'manager' => $manager,
                    'owner' => $owner,
                    'child' => $child,
                    'relation' => $relation,
                ];
            }
        }

        return $out;
    }

    protected static function isThePropertyItself(string $model): bool
    {
        return (new ReflectionClass($model))->getAttributes(PropertyItself::class) !== [];
    }

    /**
     * @return list<class-string>
     */
    public static function managersOf(string $resource): array
    {
        $managers = [];

        foreach ($resource::getRelations() as $relation) {
            foreach ($relation instanceof RelationGroup ? $relation->getManagers() : [$relation] as $manager) {
                // **A NON-STRING ENTRY IS A FAILURE, NOT A `continue`.** Filament v4 also accepts a
                // `RelationManagerConfiguration` in `getRelations()`, and skipping one would drop
                // that tab out of the sweep with the build still green — the silent-omission shape
                // this file exists to end. None is used today; converting one must turn the gate red
                // rather than quietly shrink it.
                if (! is_string($manager)) {
                    throw new \RuntimeException(sprintf(
                        '%s registers a relation this sweep cannot read (%s). Teach CrossPropertyTabs to resolve it.',
                        $resource,
                        get_debug_type($manager),
                    ));
                }

                if (class_exists($manager)) {
                    $managers[] = $manager;
                }
            }
        }

        return $managers;
    }

    protected static function relationshipOf(string $manager): ?string
    {
        $property = (new ReflectionClass($manager))->getProperty('relationship');

        $name = $property->getDefaultValue();

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return class-string|null
     */
    protected static function relatedModel(string $owner, string $relation): ?string
    {
        try {
            return (new $owner)->{$relation}()->getRelated()::class;
        } catch (\Throwable) {
            return null;   // not an Eloquent relation — nothing here can be property-owned
        }
    }
}
