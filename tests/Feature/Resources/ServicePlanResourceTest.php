<?php

use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Models\ServicePlan;
use Database\Seeders\RolesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

function makePlanFor(int $assetId, array $attrs = []): ServicePlan
{
    return ServicePlan::create(array_merge([
        'asset_id' => $assetId, 'title' => 'Lift service', 'trade_id' => tradeId('safety'),
        'frequency_unit' => 'months', 'frequency_value' => 3, 'next_due_date' => now()->toDateString(),
    ], $attrs));
}

it('gates the plan resource on facility permissions', function () {
    $this->actingAs(makeUser('operations'));
    expect(ServicePlanResource::canViewAny())->toBeTrue();
    expect(ServicePlanResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(ServicePlanResource::canViewAny())->toBeFalse();
});

it('scopes plans to the current property', function () {
    $assetA = makeAsset(['code' => 'PLA']);
    $assetB = makeAsset(['code' => 'PLB']);
    makePlanFor($assetA->id, ['title' => 'A plan']);
    makePlanFor($assetB->id, ['title' => 'B plan']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($assetA, function () {
        expect(scopedResourceQuery(ServicePlanResource::class)->pluck('title')->all())
            ->toContain('A plan')->not->toContain('B plan');
    });
});

it('rejects an out-of-scope asset_id on the plan', function () {
    $assetA = makeAsset(['code' => 'PGA']);
    $assetB = makeAsset(['code' => 'PGB']);
    $this->actingAs(makeUser('operations', [$assetA->id]));

    ServicePlanResource::assertAssetInScope($assetA->id);
    expect(true)->toBeTrue();

    expect(fn () => ServicePlanResource::assertAssetInScope($assetB->id))
        ->toThrow(HttpException::class);
});

it('coerces a zero frequency_value to 1 (never a non-advancing plan)', function () {
    $plan = makePlanFor(makeAsset()->id, ['frequency_value' => 0]);
    expect($plan->fresh()->frequency_value)->toBe(1);
});
