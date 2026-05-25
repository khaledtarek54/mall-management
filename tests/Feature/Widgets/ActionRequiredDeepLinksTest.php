<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\Charge;
use App\Models\MaintenanceRequest;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addDays(15),
    ]);

    // Seed one of each "actionable" thing so every card surfaces.
    MaintenanceRequest::create([
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

    $this->actingAs(makeUser('manager'));
});

function actionCards(): array
{
    $widget = new ActionRequired;
    $ref = new ReflectionMethod($widget, 'getViewData');
    return $ref->invoke($widget)['items'];
}

it('urgent_maintenance link filters by priority AND sorts oldest first', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'urgent_maintenance');
        expect($card)->not->toBeNull();

        $url = $card['url'];
        expect($url)
            ->toContain('tableFilters%5Bpriority%5D%5Bvalue%5D=urgent')
            ->toContain('tableSortColumn=submitted_at')
            ->toContain('tableSortDirection=asc');
    });
});

it('sla_breached link filters + sorts most-overdue first', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'sla_breached');
        expect($card)->not->toBeNull();

        expect($card['url'])
            ->toContain('sla_breached')
            ->toContain('tableSortColumn=target_resolution_at')
            ->toContain('tableSortDirection=asc');
    });
});

it('overdue_invoices link filters + sorts oldest-due-date first', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'overdue');
        expect($card)->not->toBeNull();

        expect($card['url'])
            ->toContain('overdue_only')
            ->toContain('tableSortColumn=due_date')
            ->toContain('tableSortDirection=asc');
    });
});

it('expiring_critical link filters + sorts soonest-expiring first', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'expiring_critical');
        expect($card)->not->toBeNull();

        expect($card['url'])
            ->toContain('expiring_soon')
            ->toContain('tableSortColumn=expiry_date')
            ->toContain('tableSortDirection=asc');
    });
});

it('vacant_units link filters + sorts biggest-area first', function () {
    asTenant($this->asset, function () {
        $card = collect(actionCards())->firstWhere('key', 'vacant');
        expect($card)->not->toBeNull();

        expect($card['url'])
            ->toContain('tableFilters%5Bstatus%5D%5Bvalue%5D=vacant')
            ->toContain('tableSortColumn=area_sqm')
            ->toContain('tableSortDirection=desc');
    });
});
