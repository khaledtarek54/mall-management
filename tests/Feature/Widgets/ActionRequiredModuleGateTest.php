<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\TenantRequest;
use App\Settings\ModulesSettings;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    // The ActionRequired cards are gated on the {module}.view permission of the register each one
    // links to, so these need the REAL role definitions — `makeUser()` alone creates a bare role with
    // no permissions at all, a manager that cannot exist in production. Without the seeder the widget
    // correctly returns an empty card list and the assertions below would be green over nothing.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);

    // Seed an urgent open maintenance request so the ActionRequired widget
    // would normally surface a card for it.
    TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'title' => 'AC down',
        'description' => 'Urgent',
        'status' => 'submitted',
        'priority' => 'urgent',
        'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    $this->actingAs(makeUser('manager'));
});

function actionItems(): array
{
    $widget = new ActionRequired;
    $ref = new ReflectionMethod($widget, 'getViewData');

    return $ref->invoke($widget)['items'];
}

it('surfaces an urgent_requests card when the requests module is enabled', function () {
    $settings = app(ModulesSettings::class);
    $settings->requests = true;
    $settings->save();

    asTenant($this->asset, function () {
        $keys = collect(actionItems())->pluck('key')->all();
        expect($keys)->toContain('urgent_requests');
    });
});

it('omits request cards entirely when the module is disabled', function () {
    $settings = app(ModulesSettings::class);
    $settings->requests = false;
    $settings->save();

    asTenant($this->asset, function () {
        $keys = collect(actionItems())->pluck('key')->all();
        expect($keys)->not->toContain('urgent_requests')
            ->not->toContain('sla_breached');
    });
});
