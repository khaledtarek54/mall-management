<?php

use App\Filament\Vendor\Resources\WorkOrders\WorkOrderResource as VendorWorkOrderResource;
use App\Support\ScreenGuides;
use App\Support\SearchPolicy;
use Filament\Facades\Filament;

/**
 * EVERY PANEL IS SWEPT BY THE REGISTRIES THAT FORCE A DECISION ABOUT A SCREEN (SW-130).
 *
 * `ScreenGuides::discoverScreens()` and `SearchPolicyConformanceTest::searchPolicyResources()` both
 * discovered from a hardcoded `Admin + Portal` directory list — a list someone maintains, wearing
 * the clothes of a derivation. The contractor panel shipped on 2026-08-28 and was swept by neither,
 * so its one resource was unclassified in both registries and nothing said so.
 *
 * What the blindness hid is the point. `VendorPanelProvider`'s docblock says "No global search
 * (there is nothing to search — the portal is a list of *your* jobs, and a search box over one
 * narrow list reads as an invitation to look for other people's)" — and `->globalSearch(false)` was
 * never called, so Filament's panel default left the box on, served by its STOCK provider, over
 * `facility_work_orders.reference` RAW. Never a leak (`VendorScope::jobs()` scopes the query), but
 * an unfolded query against a blob-free column: the one thing `SearchPolicy` forbids outright, and
 * the only instance of it left in the application.
 *
 * Everything below derives from `Filament::getPanels()` — the panel REGISTRY, an independent source
 * from the directory glob the fix uses. A gate that reads only the thing it guards cannot see what
 * that thing omits.
 */
function panelRegisteredResources(): array
{
    $resources = [];

    foreach (Filament::getPanels() as $id => $panel) {
        foreach ($panel->getResources() as $resource) {
            $resources[] = [$id, $resource];
        }
    }

    return $resources;
}

it('discovers a screen from every registered panel, not from a hardcoded list of two', function () {
    $discovered = ScreenGuides::discoverScreens();
    $registered = panelRegisteredResources();

    // Vacuity guard: a panel registry that resolved nothing would satisfy every loop below.
    expect(count($registered))->toBeGreaterThan(70);

    $missing = [];

    foreach ($registered as [$panelId, $resource]) {
        if (! in_array($resource, $discovered, true)) {
            $missing[] = "{$panelId}: {$resource}";
        }
    }

    expect($missing)->toBe([], 'These resources are rendered by a panel and are invisible to '
        ."ScreenGuides::discoverScreens(), so the guide gate can never ask about them:\n  "
        .implode("\n  ", $missing));
});

it('classifies every panel’s resources in BOTH registries', function () {
    $unguided = [];
    $unclassifiedSearch = [];

    foreach (panelRegisteredResources() as [$panelId, $resource]) {
        if (! ScreenGuides::has($resource) && ! ScreenGuides::isExempt($resource)) {
            $unguided[] = "{$panelId}: {$resource}";
        }

        if (! SearchPolicy::isGlobalSearchExempt($resource)
            && $resource::getGloballySearchableAttributes() === []) {
            $unclassifiedSearch[] = "{$panelId}: {$resource}";
        }
    }

    expect($unguided)->toBe([], "No guide and no exemption:\n  ".implode("\n  ", $unguided));
    expect($unclassifiedSearch)->toBe([], "Not searchable and not exempt:\n  ".implode("\n  ", $unclassifiedSearch));
});

it('searches a folded blob or nothing at all, in every panel', function () {
    // The rule `SearchPolicy` has always stated and only ever enforced over two panels of three.
    $raw = [];

    foreach (panelRegisteredResources() as [$panelId, $resource]) {
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        foreach ($resource::getGloballySearchableAttributes() as $attribute) {
            if (! str_ends_with($attribute, 'search_text')) {
                $raw[] = "{$panelId}: ".class_basename($resource)." → {$attribute}";
            }
        }
    }

    expect($raw)->toBe([], 'These global-search a RAW column, so a folded query is compared against '
        ."an unfolded value and every Arabic spelling the fold exists to fix silently misses:\n  "
        .implode("\n  ", $raw));
});

it('offers no global search box in the contractor panel, and still offers one in the operator’s', function () {
    // The panel decision, stated in `VendorPanelProvider`'s docblock since the day it was written
    // and unimplemented until 2026-09-04. No auth needed: this reads the PANEL, not the resources.
    expect(Filament::getPanel('vendor')->getGlobalSearchProvider())->toBeNull();

    // The control — without it this passes just as happily on a build where search is off
    // everywhere, which would be a far worse regression than the one being fixed.
    expect(Filament::getPanel('admin')->getGlobalSearchProvider())->not->toBeNull()
        ->and(Filament::getPanel('portal')->getGlobalSearchProvider())->not->toBeNull();

    // And the resource says it for itself, which is what `SearchPolicy`'s own gate reads.
    expect(VendorWorkOrderResource::canGloballySearch())->toBeFalse();
});
