<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\TenantRequest;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addDays(15),
    ]);
    // LeaseObserver flips $this->unit to 'occupied' on the active lease above.
    // The vacant_units card needs at least one genuinely vacant unit on the
    // same asset to surface, so make a second unit and leave it leaseless.
    makeUnit($this->asset, ['status' => 'vacant']);

    // Seed one of each "actionable" thing so every card surfaces.
    TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'title' => 'Urgent', 'description' => 'Now',
        'status' => 'in_progress',
        'priority' => 'urgent',
        'category' => 'hvac',
        'submitted_at' => now()->subHours(6),
        'target_resolution_at' => now()->subHours(1), // breached
    ]);

    makeInvoice($this->lease, [
        'balance' => 1000,
        'status' => 'overdue',
        'due_date' => now()->subDays(10),
    ]);

    // Manager needs the test asset in their assigned-assets pivot, otherwise
    // Filament's canAccessTenant gate 404s the URL.
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

function actionCards(): array
{
    $widget = new ActionRequired;
    $ref = new ReflectionMethod($widget, 'getViewData');
    return $ref->invoke($widget)['items'];
}

/**
 * Filament 4 aliases the Livewire query string: the table component's
 * `$tableSort` is published as `?sort=` and `$tableFilters` as
 * `?filters=`. Generating URLs with the property names (tableSort,
 * tableFilters) silently fails to bind on the receiving page.
 */
it('urgent_maintenance link uses filters[priority] + sort=submitted_at:asc', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'urgent_maintenance');
        expect($card)->not->toBeNull();
        expect($card['url'])
            ->toContain('filters%5Bpriority%5D%5Bvalue%5D=urgent')
            ->toContain('sort=submitted_at%3Aasc');
    });
});

it('sla_breached link uses filters[sla_breached] + sort=target_resolution_at:asc', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'sla_breached');
        expect($card['url'])
            ->toContain('filters%5Bsla_breached%5D')
            ->toContain('sort=target_resolution_at%3Aasc');
    });
});

it('overdue_invoices link uses filters[overdue_only] + sort=due_date:asc', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'overdue');
        expect($card['url'])
            ->toContain('filters%5Boverdue_only%5D')
            ->toContain('sort=due_date%3Aasc');
    });
});

it('expiring_critical link uses filters[expiring_soon] + sort=expiry_date:asc', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'expiring_critical');
        expect($card['url'])
            ->toContain('filters%5Bexpiring_soon%5D')
            ->toContain('sort=expiry_date%3Aasc');
    });
});

it('vacant_units link uses filters[status]=vacant + sort=area_sqm:desc', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'vacant');
        expect($card['url'])
            ->toContain('filters%5Bstatus%5D%5Bvalue%5D=vacant')
            ->toContain('sort=area_sqm%3Adesc');
    });
});

/**
 * Format-regression guards. The Livewire query-string alias machinery
 * publishes only the aliased keys; the raw property names never reach
 * the URL. If a future copy/paste resurrects them, the cards will
 * silently stop filtering/sorting.
 */
it('never emits Filament 3 tableSortColumn / tableSortDirection URL params', function () {
    asTenant($this->asset, function () {
        $urls = collect(actionCards())->pluck('url');
        foreach ($urls as $url) {
            expect($url)
                ->not->toContain('tableSortColumn=')
                ->not->toContain('tableSortDirection=');
        }
    });
});

it('never emits the raw tableSort / tableFilters property names — Filament 4 aliases them to sort + filters', function () {
    asTenant($this->asset, function () {
        $urls = collect(actionCards())->pluck('url');
        foreach ($urls as $url) {
            expect($url)
                ->not->toContain('tableSort=')
                ->not->toContain('tableFilters%5B');
        }
    });
});

/**
 * End-to-end: actually GET the deep-link URL through the kernel and
 * confirm Filament reflects the filter + sort state in the rendered
 * Livewire payload. This is the test that would have caught the bug.
 */
it('the rendered page sets $tableSort and $tableFilters from the URL', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'overdue');
        $path = parse_url($card['url'], PHP_URL_PATH) . '?' . parse_url($card['url'], PHP_URL_QUERY);

        $response = $this->get($path);
        $response->assertOk();

        $html = $response->getContent();

        // Livewire serialises the component state into the page. We expect
        // `tableSort":"due_date:asc"` and `tableFilters":[{"overdue_only":...
        // to show up in the wire-snapshot blob.
        expect($html)
            ->toContain('due_date:asc')
            ->toContain('overdue_only');
    });
});
