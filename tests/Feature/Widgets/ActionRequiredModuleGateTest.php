<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\TenantRequest;
use App\Settings\ModulesSettings;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);

    // Seed an urgent open maintenance request so the ActionRequired widget
    // would normally surface a card for it.
    TenantRequest::create([
        'reference' => 'MR-' . uniqid(),
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

it('surfaces an urgent_maintenance card when the maintenance module is enabled', function () {
    $settings = app(ModulesSettings::class);
    $settings->maintenance = true;
    $settings->save();

    asTenant($this->asset, function () {
        $keys = collect(actionItems())->pluck('key')->all();
        expect($keys)->toContain('urgent_maintenance');
    });
});

it('omits maintenance cards entirely when the module is disabled', function () {
    $settings = app(ModulesSettings::class);
    $settings->maintenance = false;
    $settings->save();

    asTenant($this->asset, function () {
        $keys = collect(actionItems())->pluck('key')->all();
        expect($keys)->not->toContain('urgent_maintenance')
            ->not->toContain('sla_breached');
    });
});
