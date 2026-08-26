<?php

/**
 * Every admin list, read by an operator who holds ONE mall — and not a row from the other.
 *
 * The isolation suite beside this one is thorough and hand-picked: it names Invoices, Payments,
 * Credit notes, Deposits, Leases, Units, Declarations, Departments, Owner requests and the money-out
 * set, and proves each properly. What no test does is ask the question of ALL of them at once, and
 * the register is 66 resources. `PropertyIsolationConformanceTest` closes part of that gap but
 * checks a WEAKER property — that a model is CLASSIFIED and a resource scopes — which is a claim
 * about the code's shape, not about the rows a real operator gets back.
 *
 * ## Why the operator is pinned to the EMPTY mall
 *
 * Measured on `DemoSeeder`: of the 37 models carrying a direct `asset_id`, **34 have rows in one
 * property only**. Pinning the operator to the rich mall and asking "do you see the other one" would
 * therefore be asking a question about three models and reporting it as a sweep over sixty-six —
 * the shape of gate defect CLAUDE.md warns about most often.
 *
 * Inverted, it is strong: the operator holds the SPARSE mall, every list is asked, and the RICH
 * mall's hundreds of rows are what must never come back. Coverage is then bounded by "does this
 * list have data at all", which is most of them — and the third test below MEASURES that bound
 * instead of assuming it.
 *
 * ## The one resource this cannot see
 *
 * `MarketingBudgetResource` is the single resource of 66 that leaves Filament's own tenancy scope
 * ON (`isScopedToTenant() === true`) instead of scoping itself in `getEloquentQuery()`. That scope
 * is a GLOBAL SCOPE registered in `Panel::boot()`, and `asTenant()` — which calls
 * `Filament::setTenant()` directly — never registers it. So under this harness that resource looks
 * unscoped while in production it is not (verified over real HTTP: the list compiles
 * `asset_id in (?)` and another mall's record is a 404). Excluding it silently would be the bug
 * this file exists to catch, so it is excluded by DERIVATION and covered over the real route below.
 */

use App\Models\Asset;
use App\Support\Navigation;
use App\Support\PropertyIsolation;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/**
 * Which property a row belongs to, answered the way the register defines it.
 *
 * Recursive on purpose: `Charge` is `via: 'lease.unit'` and `Lease` is itself `via: 'unit'`, so a
 * one-hop resolver answers null for much of the register and the sweep quietly passes on every row
 * it could not classify. `null` means portfolio-level OR unresolvable, and the caller has to tell
 * those apart — it is not a licence to skip the row.
 */
function rowAssetId(Model $record, int $depth = 0): ?int
{
    if ($depth > 6) {
        return null;
    }

    $model = $record::class;

    // The property IS its own scope, so its key is the answer.
    if (in_array($model, PropertyIsolation::selfModels(), true)) {
        return (int) $record->getKey();
    }

    if (! PropertyIsolation::isOwned($model)) {
        return null;
    }

    if (PropertyIsolation::isDirect($model)) {
        $id = $record->getAttribute('asset_id');

        return $id === null ? null : (int) $id;
    }

    $related = data_get($record, (string) PropertyIsolation::linkageFor($model));

    return $related instanceof Model ? rowAssetId($related, $depth + 1) : null;
}

/**
 * Admin list pages, split by HOW they scope.
 *
 * @return array{self: array<int, class-string>, auto: array<int, class-string<resource>>}
 */
function listPagesByScopingStyle(): array
{
    $self = [];
    $auto = [];

    foreach (Navigation::placed() as $screen) {
        if (! is_subclass_of($screen, Resource::class)) {
            continue;
        }

        $page = $screen::getPages()['index']->getPage() ?? null;

        if ($page === null || ! is_subclass_of($page, ListRecords::class)) {
            continue;
        }

        $screen::isScopedToTenant() ? $auto[] = $screen : $self[] = $page;
    }

    return ['self' => $self, 'auto' => $auto];
}

/**
 * Read every list this user can open under this tenant, and report each row's property.
 *
 * @return array{seen: array<string, array<int, int|null>>, opened: int, rows: int}
 */
function sweepListsForProperties(Asset $tenant): array
{
    $seen = [];
    $opened = 0;
    $rows = 0;

    asTenant($tenant, function () use (&$seen, &$opened, &$rows) {
        foreach (listPagesByScopingStyle()['self'] as $page) {
            if (! $page::getResource()::canAccess()) {
                continue;
            }

            $opened++;

            foreach (tableRows(Livewire::test($page)) as $record) {
                $rows++;
                $seen[$page][] = rowAssetId($record);
            }
        }
    });

    return ['seen' => $seen, 'opened' => $opened, 'rows' => $rows];
}

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $properties = Asset::query()
        ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
        ->orderBy('id')
        ->get();

    expect($properties->count())->toBeGreaterThanOrEqual(2, 'The demo data has fewer than two properties — isolation cannot be tested.');

    // `theirs` is the mall the demo fills; `mine` is the other one. See the class docblock.
    $this->theirs = $properties[0];
    $this->mine = $properties[1];
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('never returns another mall\'s row to an operator who holds one mall', function () {
    $this->flushSession();
    $this->actingAs(makeUser('manager', [$this->mine->id]));

    $result = sweepListsForProperties($this->mine);

    $leaks = [];

    foreach ($result['seen'] as $page => $assetIds) {
        foreach (array_unique($assetIds, SORT_REGULAR) as $assetId) {
            if ($assetId === null || $assetId === $this->mine->id) {
                continue;
            }

            $leaks[] = class_basename($page).' returned a row belonging to asset '.$assetId
                .' (the operator holds only '.$this->mine->id.')';
        }
    }

    expect($leaks)->toBe([], "Cross-property rows returned to a restricted operator:\n".implode("\n", $leaks));

    // The scope must not simply have emptied the panel: the operator can still OPEN the lists.
    expect($result['opened'])->toBeGreaterThan(40, 'The restricted operator could open almost no list.');
});

it('proves the rows it refuses actually exist, and says how much it could not classify', function () {
    // Every assertion above is a refusal, and a refusal proves nothing unless the thing refused is
    // there. Two measurements, one sweep — the bound on what this file can prove:
    //
    //  - how many lists hold a row that a leak WOULD have surfaced, and
    //  - how many rows `rowAssetId()` could not resolve, since the leak check skips those and a
    //    large unresolved share would make it read as a sweep over all of the panel while looking
    //    at a fraction of it.
    $this->flushSession();
    $this->actingAs(makeUser('super_admin'));

    $result = sweepListsForProperties($this->theirs);

    $listsWithLeakableRows = collect($result['seen'])
        ->filter(fn (array $ids): bool => in_array($this->theirs->id, $ids, true))
        ->keys();

    expect($result['opened'])->toBeGreaterThan(40, 'The portfolio sweep opened almost no lists.');
    expect($result['rows'])->toBeGreaterThan(200, 'The demo data is too thin for this to prove anything.');
    expect($listsWithLeakableRows->count())->toBeGreaterThan(20,
        'Fewer than 20 lists hold a row belonging to the other mall — the refusal above is being asked of too little.');

    $all = collect($result['seen'])->flatten(1);
    $unresolved = $all->filter(fn (?int $id): bool => $id === null)->count();

    expect($unresolved / max($all->count(), 1))->toBeLessThan(0.35,
        "Too many rows could not be resolved to a property ({$unresolved} of {$all->count()}) — the leak check is looking at too little.");
});

it('refuses the whole panel under a mall the operator does not hold', function () {
    // The perimeter, and the one the sweep above cannot see. With a tenant selected, most resources
    // scope by the SELECTED property rather than by the assigned set — so a leak needs the operator
    // to reach a property they do not hold in the first place, which is a question about the URL,
    // not about a query. `/admin/{mall}/…` is a route parameter an operator can simply retype.
    //
    // Every screen, not a sample: `canAccessTenant()` is checked once by `IdentifyTenant`, but a
    // screen that registered its own route outside the tenant group would bypass it, and only
    // asking all of them can say that none does.
    $this->flushSession();
    $this->actingAs(makeUser('manager', [$this->mine->id]));

    $reachable = [];
    $refused = 0;

    foreach (Navigation::placed() as $screen) {
        $url = is_subclass_of($screen, Resource::class)
            ? $screen::getUrl('index', tenant: $this->theirs)
            : $screen::getUrl(tenant: $this->theirs);

        $status = $this->get($url)->status();

        // 404 is the right answer — it refuses without confirming the mall exists, the same rule
        // `/api/v1` follows for a cross-tenant id.
        $status === 200
            ? $reachable[] = class_basename($screen).' answered 200 under a mall the operator does not hold'
            : $refused++;
    }

    expect($reachable)->toBe([], implode("\n", $reachable));

    // The control: the same operator reaches those screens under their OWN mall, so the refusals
    // above are the property boundary and not a broken fixture.
    $ownMall = 0;

    asTenant($this->mine, function () use (&$ownMall) {
        foreach (Navigation::placed() as $screen) {
            $screen::canAccess() && $ownMall++;
        }
    });

    expect($refused)->toBeGreaterThan(90);
    expect($ownMall)->toBeGreaterThan(30, 'The operator can reach nothing anywhere — the refusals prove no boundary.');
});

it('scopes the ONE resource that leaves Filament\'s own tenancy on, over the real route', function () {
    // Derived, never named: a second resource that starts relying on the auto-scope is covered by
    // BEING one. It has to go through HTTP because the global scope is registered in `Panel::boot()`
    // and `asTenant()` does not run it — which is exactly why this resource has no coverage in the
    // Livewire-driven isolation suite, and why a regression in it would be silent.
    $autoScoped = listPagesByScopingStyle()['auto'];

    expect($autoScoped)->not->toBeEmpty('No auto-scoped resource found — if the last one converted, delete this test.');

    $this->flushSession();
    $this->actingAs(makeUser('super_admin', [$this->mine->id]));

    foreach ($autoScoped as $resource) {
        $model = $resource::getModel();

        $theirRecord = $model::query()->where('asset_id', $this->theirs->id)->first();

        if ($theirRecord === null) {
            continue;
        }

        // The list under MY mall must not be able to reach THEIR record...
        $this->get($resource::getUrl('index', tenant: $this->mine))->assertOk();

        // ...and naming it directly is a 404, not a 403: no existence enumeration, the rule the
        // mobile API already follows.
        $this->get($resource::getUrl('edit', ['record' => $theirRecord], tenant: $this->mine))
            ->assertNotFound();

        // The control: my own record on the same screen opens.
        $mine = $model::query()->where('asset_id', $this->mine->id)->first();

        if ($mine !== null) {
            $this->get($resource::getUrl('edit', ['record' => $mine], tenant: $this->mine))->assertOk();
        }
    }
});
