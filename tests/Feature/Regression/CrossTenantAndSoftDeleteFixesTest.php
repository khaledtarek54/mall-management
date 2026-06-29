<?php

use App\Actions\Api\V1\Devices\RegisterDeviceTokenAction;
use App\Models\DeviceToken;
use App\Models\MarketingBudget;

/**
 * Regression (pass-2 review):
 *  - #7 a device push token maps to ONE tenant — registering it for a new
 *    tenant must drop another tenant's stale row (no cross-tenant push leak).
 *  - #8 forPeriod() must restore a soft-deleted MarketingBudget instead of
 *    colliding on the (asset, year) unique key + crashing billing.
 */
it('drops another tenant\'s row for the same device token (no cross-tenant push leak)', function () {
    $a = makeTenant();
    $b = makeTenant();
    $action = app(RegisterDeviceTokenAction::class);

    $action->handle($a, ['platform' => 'android', 'token' => 'TKN-SHARED', 'device_name' => 'pixel']);
    $action->handle($b, ['platform' => 'android', 'token' => 'TKN-SHARED', 'device_name' => 'pixel']);

    $rows = DeviceToken::where('token', 'TKN-SHARED')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tenant_id)->toBe($b->id);
});

it('restores a soft-deleted marketing budget instead of colliding', function () {
    $asset = makeAsset();

    $first = MarketingBudget::forPeriod($asset->id, 2026);
    $first->delete(); // soft delete — still occupies the (asset, year) unique key

    $second = MarketingBudget::forPeriod($asset->id, 2026); // must not crash

    expect($second->id)->toBe($first->id)
        ->and($second->trashed())->toBeFalse();
});
