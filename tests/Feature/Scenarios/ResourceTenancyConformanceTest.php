<?php

/*
|--------------------------------------------------------------------------
| Every admin list page must actually LOAD its rows under a selected property
|--------------------------------------------------------------------------
| Filament scopes a tenant-scoped resource by asking the model for an `asset` relationship. A
| PORTFOLIO-SHARED model — a payment rail, an expense category, the merchandising mix — has none, so
| the panel throws
|
|     LogicException: The model [App\Models\RetailCategory] does not have a relationship named [asset]
|
| the moment the table paginates. That is a 500 on the LIST PAGE, i.e. on every visit, and it shipped
| on SIX resources at once — four of them for a full day, two of them within the hour.
|
| ## Why nothing caught it
|
| Every existing sweep stops one call short. `ResourceFormSmokeTest` mounts CREATE pages.
| `ScreenGuideConformanceTest` checks the guide is mounted. `ViewActionCoverageTest` does mount every
| List page under a real tenant — and calls `->getTable()`, which BUILDS the table. The tenant scope
| is applied when the query RUNS. So the manifest, the smoke tests and the gates were all green over
| six screens that could not be opened.
|
| This file therefore checks the invariant twice, cheaply and then expensively:
|
|   1. a static-ish sweep — every resource that says it is tenant-scoped must have a model that can
|      answer the ownership relationship the panel will ask for. Runs in milliseconds, covers all;
|   2. a real pagination pass over every list page under a selected property, which is the call the
|      other sweeps never make.
|
| CLAUDE.md's rule, one layer along: **a query the suite never EXECUTES is not covered by the suite.**
*/

use App\Models\Asset;
use App\Models\RetailCategory;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** @return array<int, class-string<resource>> */
function tenancyAdminResources(): array
{
    return collect(File::allFiles(app_path('Filament/Admin/Resources')))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), 'Resource.php'))
        ->map(function ($f) {
            $rel = str_replace([app_path('Filament/Admin/Resources').'/', '.php'], '', $f->getPathname());

            return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, Resource::class))
        ->values()
        ->all();
}

it('gives every tenant-scoped resource a model the panel can scope', function () {
    $resources = tenancyAdminResources();

    // The premise: discovery found something. A sweep that silently stopped collecting reports a
    // clean run — this project has been bitten by that three times.
    expect(count($resources))->toBeGreaterThan(40);

    $offenders = [];
    $scoped = 0;

    foreach ($resources as $class) {
        $model = $class::getModel();

        if (! $model || ! class_exists($model)) {
            continue;
        }

        if (! $class::isScopedToTenant()) {
            continue;
        }

        $scoped++;

        // The relationship Filament will ask for. `ownershipRelationship` defaults to the panel's,
        // which is `asset`; a resource may name its own.
        $relationship = $class::getTenantOwnershipRelationshipName();

        if (! method_exists($model, $relationship)) {
            $offenders[] = class_basename($class)." → {$model} has no `{$relationship}()`";
        }
    }

    // The other half of the premise: at least one resource IS scoped, so the loop body is reachable
    // rather than skipped for everything. It is a SMALL population by design — 61 of 62 resources
    // opt out and scope themselves in `getEloquentQuery()`, because most of them reach their
    // property through a relation chain rather than a direct `asset_id`. That is exactly why the
    // check matters: the default is ON, so a resource that simply forgets to opt out is broken, and
    // six of them did.
    expect($scoped)->toBeGreaterThan(0);

    expect($offenders)->toBe([], implode("\n", [
        'These resources are tenant-scoped and their models cannot answer the relationship Filament',
        'will ask for, so the list page throws a LogicException as soon as the table paginates:',
        '  '.implode("\n  ", $offenders),
        '',
        'A portfolio-shared catalogue should `use BypassesFilamentTenantAutoScope;` — that turns off',
        'BOTH the read scope and the create hook. A property-owned model needs a real `asset()`.',
    ]));
});

it('paginates every admin list page with a property selected', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'TN']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $pages = collect(File::allFiles(app_path('Filament/Admin/Resources')))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) {
            $rel = str_replace([app_path('Filament/Admin/Resources').'/', '.php'], '', $f->getPathname());

            return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ListRecords::class))
        ->values();

    expect($pages)->not->toBeEmpty();

    $failures = [];

    asTenant($asset, function () use ($pages, &$failures) {
        foreach ($pages as $page) {
            try {
                // `getTableRecords()`, not `getTable()`. Building the table does not apply the
                // tenant scope — RUNNING the query does, and that distinction is the whole bug.
                Livewire::test($page)->instance()->getTableRecords();
            } catch (Throwable $e) {
                $failures[] = class_basename($page).': '.class_basename($e).' — '.Str::limit($e->getMessage(), 160);
            }
        }
    });

    expect($failures)->toBe([], "These list pages throw with a property selected:\n  ".implode("\n  ", $failures));
})->group('slow');

it('proves the pagination pass can fail', function () {
    // Mutation, encoded. The pass above is only worth its runtime if a resource that cannot scope
    // itself really does throw when the query runs — otherwise it is another sweep that builds
    // something and never asks it a question.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'TP']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($asset) {
        $query = RetailCategory::query();

        expect(fn () => \Filament\Resources\Resource::scopeEloquentQueryToTenant($query, $asset))
            ->toThrow(LogicException::class);
    });
});
