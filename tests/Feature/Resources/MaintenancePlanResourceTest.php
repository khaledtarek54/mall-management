<?php

use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use App\Models\MaintenancePlan;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makePlanFor(int $assetId, array $attrs = []): MaintenancePlan
{
    return MaintenancePlan::create(array_merge([
        'asset_id' => $assetId, 'title' => 'Lift service', 'category' => 'safety',
        'frequency_unit' => 'months', 'frequency_value' => 3, 'next_due_date' => now()->toDateString(),
    ], $attrs));
}

it('gates the plan resource on preventive_maintenance permissions', function () {
    $this->actingAs(makeUser('operations'));
    expect(MaintenancePlanResource::canViewAny())->toBeTrue();
    expect(MaintenancePlanResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(MaintenancePlanResource::canViewAny())->toBeFalse();
});

it('scopes plans to the current property', function () {
    $assetA = makeAsset(['code' => 'PLA']);
    $assetB = makeAsset(['code' => 'PLB']);
    makePlanFor($assetA->id, ['title' => 'A plan']);
    makePlanFor($assetB->id, ['title' => 'B plan']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(MaintenancePlanResource::class)->pluck('title')->all())
            ->toContain('A plan')->not->toContain('B plan');
    });
});

it('rejects an out-of-scope asset_id on the plan', function () {
    $assetA = makeAsset(['code' => 'PGA']);
    $assetB = makeAsset(['code' => 'PGB']);
    $this->actingAs(makeUser('operations', [$assetA->id]));

    MaintenancePlanResource::assertAssetInScope($assetA->id);
    expect(true)->toBeTrue();

    expect(fn () => MaintenancePlanResource::assertAssetInScope($assetB->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('coerces a zero frequency_value to 1 (never a non-advancing plan)', function () {
    $plan = makePlanFor(makeAsset()->id, ['frequency_value' => 0]);
    expect($plan->fresh()->frequency_value)->toBe(1);
});
