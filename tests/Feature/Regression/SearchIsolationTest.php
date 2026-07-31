<?php

/*
|--------------------------------------------------------------------------
| Search must not become a way around property isolation
|--------------------------------------------------------------------------
| Global search is the one surface that queries EVERY resource at once, from a box on every page,
| with no property filter visible next to it. If any resource's search path stepped outside its
| property scope, this is where a leak would surface — and it would look like a feature ("search
| finds everything!") rather than a bug.
|
| It holds by construction rather than by a second copy of the rules: `getGlobalSearchResults()`
| runs through `getGlobalSearchEloquentQuery()` → `getEloquentQuery()`, which is exactly where
| `ScopesViaProperty` and the hand-rolled `asset_id` clauses live. This file exists to prove that
| inheritance still holds, because "it's inherited" is a claim that stops being true the moment
| someone overrides `getGlobalSearchEloquentQuery()` to add an eager load and writes
| `Model::query()` instead of `parent::`.
|
| Written to fail loudly: each assertion names the resource and the foreign record it saw.
*/

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Support\Search\AtriomGlobalSearchProvider;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    // Permissions, not just roles. Filament drops any result whose
    // `getGlobalSearchResultUrl()` is blank, and that URL is built from
    // `canView()`/`canEdit()` — so without the permission catalogue every search
    // in this file returns zero and every REFUSAL assertion below passes for the
    // wrong reason. That is precisely why each refusal here is paired with a
    // control that must find something.
    $this->seed(RolesPermissionsSeeder::class);

    $this->propertyA = makeAsset(['name' => 'Atriom Walk', 'code' => 'AW']);
    $this->propertyB = makeAsset(['name' => 'Nile Plaza', 'code' => 'NP']);

    // Same distinctive token in BOTH properties. A leak is then unambiguous: the query matches a
    // record on each side, so a scoped search returning two hits can only mean the scope failed.
    $this->unitA = makeUnit($this->propertyA, ['code' => 'ZEBRAUNIT']);
    $this->unitB = makeUnit($this->propertyB, ['code' => 'ZEBRAUNIT']);

    $this->tenantA = makeTenant(['name' => 'Zebra Trading A']);
    $this->tenantB = makeTenant(['name' => 'Zebra Trading B']);

    $this->leaseA = makeLease($this->unitA, $this->tenantA, ['reference' => 'ZEBRALEASE-A']);
    $this->leaseB = makeLease($this->unitB, $this->tenantB, ['reference' => 'ZEBRALEASE-B']);

    $this->invoiceA = makeInvoice($this->leaseA);
    $this->invoiceB = makeInvoice($this->leaseB);
});

it('never returns another property\'s units from global search', function () {
    $codes = asTenant($this->propertyA, fn () => UnitResource::getGlobalSearchResults('ZEBRAUNIT'));

    expect($codes)->toHaveCount(1, 'searching from Atriom Walk returned a unit from another property');
});

it('never returns another property\'s leases from global search', function () {
    // Both leases share the ZEBRALEASE token, so a scope failure returns two.
    $results = asTenant($this->propertyA, fn () => LeaseResource::getGlobalSearchResults('ZEBRALEASE'));

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('ZEBRALEASE-A');
});

it('never returns another property\'s invoices from global search', function () {
    // Invoice reaches its property through lease.unit — the indirect chain, which is the one that
    // breaks first if someone rewrites getGlobalSearchEloquentQuery() carelessly.
    $results = asTenant($this->propertyA, fn () => InvoiceResource::getGlobalSearchResults($this->invoiceB->number));

    expect($results)->toHaveCount(0, 'an invoice from another property was reachable by its number');
});

it('finds an invoice by a tenant name only within the current property', function () {
    // The relation-search path (tenant.search_text) is a `whereHas` bolted onto the scoped query.
    // If the scope were applied after the OR rather than around it, this is where it would show.
    $inA = asTenant($this->propertyA, fn () => InvoiceResource::getGlobalSearchResults('Zebra Trading'));
    $inB = asTenant($this->propertyB, fn () => InvoiceResource::getGlobalSearchResults('Zebra Trading'));

    expect($inA)->toHaveCount(1)
        ->and($inA->first()->title)->toBe($this->invoiceA->number)
        ->and($inB)->toHaveCount(1)
        ->and($inB->first()->title)->toBe($this->invoiceB->number);
});

it('scopes the whole provider, not just the resources anyone thought to check', function () {
    // Drives the real provider — every registered resource at once — and asserts that nothing it
    // hands back belongs to another property. This is the assertion that covers resource #48.
    asTenant($this->propertyA, function () {
        Filament::setCurrentPanel('admin');

        $results = (new AtriomGlobalSearchProvider)->getResults('ZEBRA');

        $foreign = [];

        foreach ($results->getCategories() as $category => $rows) {
            foreach ($rows as $row) {
                // Every seeded B-side record carries a title ending in -B or belongs to Nile Plaza.
                if (str_contains((string) $row->title, 'Trading B') || str_contains((string) $row->title, 'LEASE-B')) {
                    $foreign[] = $category.': '.$row->title;
                }
            }
        }

        expect($foreign)->toBe([], 'global search leaked records from another property: '.implode(', ', $foreign));
    });
});

it('refuses a query shorter than the floor instead of scanning every table', function () {
    // One character matches most of the database and costs a full scan per resource. The floor is
    // measured on the FOLD, so a stray diacritic is not a character.
    asTenant($this->propertyA, function () {
        Filament::setCurrentPanel('admin');

        $provider = new AtriomGlobalSearchProvider;

        expect($provider->getResults('Z')->getCategories())->toHaveCount(0)
            ->and($provider->getResults('-')->getCategories())->toHaveCount(0)
            ->and($provider->getResults('  ')->getCategories())->toHaveCount(0);
    });
});

it('still answers a query at exactly the floor', function () {
    // Guards against fixing the line above by making the floor so high that real searches break.
    asTenant($this->propertyA, function () {
        Filament::setCurrentPanel('admin');

        expect((new AtriomGlobalSearchProvider)->getResults('ZE')->getCategories())->not->toHaveCount(0);
    });
});

it('keeps a property-restricted operator inside their own property', function () {
    // Deliberately NOT tested through "All Properties" mode, even though `ScopesViaProperty` has a
    // `visibleAssetIds()` branch for it: that mode was removed (the ALL pseudo-asset is
    // unselectable and /admin/ALL 404s), so a test driving it would exercise a state no route can
    // produce — and would fail on URL generation, since every resource route needs a {tenant}.
    // What IS reachable, and what this asserts, is an accounting user assigned to one property
    // only. The role matters: `getGlobalSearchResultUrl()` is built from canView()/canEdit(), so a
    // role without `invoices.view` returns zero for BOTH halves and the pair proves nothing.
    $restricted = makeUser('accounting', [$this->propertyA->id]);
    auth()->login($restricted);
    Filament::setCurrentPanel('admin');

    $foreign = asTenant($this->propertyA, fn () => InvoiceResource::getGlobalSearchResults($this->invoiceB->number));

    expect($foreign)->toHaveCount(0, 'an accounting user assigned only to Atriom Walk found a Nile Plaza invoice');
});

it('still lets that same operator find their own property\'s records', function () {
    // The control. A refusal test passes just as happily when search is broken outright — as this
    // very file proved when it first ran green on zero results for the wrong reason.
    $restricted = makeUser('accounting', [$this->propertyA->id]);
    auth()->login($restricted);
    Filament::setCurrentPanel('admin');

    $own = asTenant($this->propertyA, fn () => InvoiceResource::getGlobalSearchResults($this->invoiceA->number));

    expect($own)->toHaveCount(1)
        ->and($own->first()->title)->toBe($this->invoiceA->number);
});

it('hides a resource from global search when the role cannot access it', function () {
    // canGloballySearch() calls canAccess(). If that ever stopped being true, global search would
    // become a read-anything bypass around every RBAC gate in the panel at once.
    $viewer = makeUser('viewer', [$this->propertyA->id]);

    auth()->login($viewer);
    Filament::setCurrentPanel('admin');

    asTenant($this->propertyA, function () {
        $inaccessible = [];

        foreach (Filament::getResources() as $resource) {
            if ($resource::canGloballySearch() && ! $resource::canAccess()) {
                $inaccessible[] = class_basename($resource);
            }
        }

        expect($inaccessible)->toBe([], 'searchable despite being inaccessible: '.implode(', ', $inaccessible));
    });
});
