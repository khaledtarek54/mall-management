<?php

use App\Support\Search\AtriomGlobalSearchProvider;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * ⌘K reaches the SCREENS, not only the records (UX5-04).
 *
 * The palette searched resources only, so the 33 report and utility PAGES — the VAT return,
 * month-end close, the rent roll, the ageing report — were reachable by scanning a fourteen-group
 * sidebar while the shortcut UX-28 advertises to every operator could not find them. A search box
 * that answers for half the panel is worse than one that answers for none: the half it misses
 * reads as absent rather than as elsewhere.
 *
 * The entries come from `AssistantCorpus`, which already scores every screen and report in both
 * languages and carries the operator's own vocabulary. This pins the two properties that matter
 * at THIS seam: a screen is findable, and it is findable only by someone who may open it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Category label => [titles] for a query run as $role. */
function paletteScreens(string $query, string $role, $asset): array
{
    $user = makeUser($role, [$asset->id]);
    test()->actingAs($user);
    Filament::setTenant($asset);

    $results = app(AtriomGlobalSearchProvider::class)->getResults($query);
    $label = (string) __('admin.search.screens');

    foreach ($results?->getCategories() ?? [] as $category => $rows) {
        if ($category === $label) {
            return collect($rows)->map(fn ($r) => (string) $r->title)->all();
        }
    }

    return [];
}

it('finds a report PAGE, which the palette could not reach before', function () {
    $titles = paletteScreens('vat return', 'super_admin', $this->asset);

    expect($titles)->not->toBeEmpty()
        ->and(collect($titles)->contains(fn ($t) => str_contains(mb_strtolower($t), 'vat')))->toBeTrue();
});

it('offers no screen a role may not open — and still offers one it may', function () {
    // A `vendor` contact's whole grant is five keys under a docblock reading "NO tenants/leases/
    // financials/HR/GL". Nothing in the VAT return is theirs to see.
    expect(paletteScreens('vat return', 'vendor', $this->asset))->toBe([]);

    // THE CONTROL, without which a category that never renders would satisfy the refusal above
    // and read as a pass.
    expect(paletteScreens('vat return', 'accounting', $this->asset))->not->toBeEmpty();
});

it('requires EVERY word to land, not just one of them', function () {
    // The word "report" alone matches much of the corpus — the control below proves it does — so
    // without the all-words rule this query would answer with a spray of loosely-related screens
    // above nothing the operator asked for. A palette that answers everything is one people stop
    // opening.
    expect(paletteScreens('report zzzqxnomatch', 'super_admin', $this->asset))->toBe([]);

    // THE CONTROL: the same query minus the unmatchable word does return screens, so the emptiness
    // above is the rule biting and not the query being unanswerable.
    expect(paletteScreens('report', 'super_admin', $this->asset))->not->toBeEmpty();
});

it('never offers more than the ceiling, however broad the query', function () {
    $titles = paletteScreens('report', 'super_admin', $this->asset);

    expect(count($titles))->toBeLessThanOrEqual(AtriomGlobalSearchProvider::MAX_SCREEN_RESULTS);
});
