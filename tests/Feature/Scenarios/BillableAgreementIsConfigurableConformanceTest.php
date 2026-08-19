<?php

use App\Contracts\BillableAgreement;
use App\Filament\Imports\ChargeImporter;
use App\Models\Lease;
use App\Models\UnitOwnership;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Every billable agreement can actually be GIVEN something to bill.
 *
 * **The gap this closes (pre-staging QA, F-01).** `BillUnitOwnershipsService` bills an ownership
 * from its `charges` rows and skips it when there are none — and for module 37's whole life, no
 * surface in the application created such a row. The resource had no relation managers, the form
 * had no repeater, `ChargeScheduleRelationManager` was mounted only on `LeaseResource`, and
 * `ChargeImporter` resolved a `lease_reference` only. An operator registered a sold unit, the
 * ownership read `handed_over`, `isBillableForPeriod()` returned true, and the monthly run reported
 * it as an unremarkable `skipped` — every month, for ever.
 *
 * Every existing gate would have passed that. `ServiceReachability` proves a SERVICE can be
 * started; it does not prove the DATA that service needs can be created. `PropertyIsolation` proves
 * the rows are scoped. `ScreenGuides` proves each screen explains itself. Nothing asked the one
 * question that mattered: *can anybody put a charge on this agreement?*
 *
 * So this gate is deliberately about REACHABILITY rather than correctness. It fails when a new
 * `BillableAgreement` ships with no way to configure what it bills — which is the state module 37
 * was in, and the state an import-only migration would put a third agreement kind in tomorrow.
 *
 * **Two roads count, and both are checked**: a relation manager on the agreement's own resource
 * (how an operator does one), and the charge importer (how a migration does a thousand). Either
 * alone is enough to be reachable; neither is enough on its own to be *usable at scale*, which is
 * why the second assertion exists separately.
 */

/** Every concrete model implementing the billable-agreement contract. */
function billableAgreementModels(): array
{
    return collect(File::files(app_path('Models')))
        ->map(fn ($f) => 'App\\Models\\'.$f->getFilenameWithoutExtension())
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class))
        ->filter(fn (string $class) => (new ReflectionClass($class))->implementsInterface(BillableAgreement::class))
        ->values()
        ->all();
}

/** The Filament resource whose model is $model, if the panel registers one. */
function resourceForModel(string $model): ?string
{
    return collect(Filament::getPanel('admin')->getResources())
        ->first(fn (string $resource) => is_subclass_of($resource, Resource::class)
            && $resource::getModel() === $model);
}

beforeEach(function () {
    seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('finds the billable agreements — a sweep that matched nothing would pass silently', function () {
    // The predecessor of this gate's shape filtered on a trait FQCN that does not exist in the
    // version we ship and was green for a year while sweeping zero models. Assert the sweep bit.
    expect(billableAgreementModels())
        ->toContain(Lease::class)
        ->toContain(UnitOwnership::class);
});

it('gives every billable agreement a screen that can configure what it bills', function () {
    $unreachable = [];

    foreach (billableAgreementModels() as $model) {
        $resource = resourceForModel($model);

        if ($resource === null) {
            $unreachable[] = class_basename($model).': no admin resource at all';

            continue;
        }

        // A relation manager whose relationship IS `charges` — the same test whichever class it is,
        // so a purpose-built one for a future agreement kind counts without being named here.
        $configures = collect($resource::getRelations())
            ->filter(fn ($relation) => is_string($relation) && is_subclass_of($relation, RelationManager::class))
            ->contains(function (string $relation): bool {
                $property = (new ReflectionClass($relation))->getProperty('relationship');
                $property->setAccessible(true);

                return $property->getValue() === 'charges';
            });

        if (! $configures) {
            $unreachable[] = class_basename($model).': '.class_basename($resource)
                .' has no relation manager over `charges`, so nothing can give it a schedule';
        }
    }

    expect($unreachable)->toBe([], "A billable agreement nobody can configure is billed nothing, for ever:\n  "
        .implode("\n  ", $unreachable));
});

it('lets a migration load a schedule for every billable agreement', function () {
    // One operator keying a schedule is reachability; a thousand rows arriving from the outgoing
    // system is what actually happens at go-live. F-01 was fixed at the screen first and the import
    // door stayed open behind it.
    $columns = collect(ChargeImporter::getColumns())->map(fn ($column) => $column->getName());

    $missing = [];

    foreach (billableAgreementModels() as $model) {
        // Each agreement is identified in the file by the column its own `invoiceLinkAttributes()`
        // names — `lease_id` → `lease_reference`, `unit_ownership_id` → `ownership_reference` —
        // so a third kind needs a column here and no change to this test.
        // `getForeignKey()`, not `invoiceLinkAttributes()`: the latter is an instance method whose
        // values are ids, and an UNSAVED model has none — so filtering it on the value yields
        // nothing and the gate reports "needs a [] column" about every agreement. Caught by
        // mutation-testing this gate rather than by reading it.
        $key = (new $model)->getForeignKey();

        $expected = str_replace('_id', '_reference', (string) $key);
        $expected = $expected === 'unit_ownership_reference' ? 'ownership_reference' : $expected;

        if (! $columns->contains($expected)) {
            $missing[] = class_basename($model)." needs a [{$expected}] column on ChargeImporter";
        }
    }

    expect($missing)->toBe([], "A migration cannot load these agreements' schedules:\n  ".implode("\n  ", $missing));
});
