<?php

use App\Notifications\MaintenanceSlaBreachedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('alerts the property owner when a maintenance request breaches SLA (MNT-5)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($asset);
    $req = makeMaintenanceRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('maintenance:scan-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($owner, MaintenanceSlaBreachedNotification::class);
    expect($req->refresh()->sla_breach_notified_at)->not->toBeNull();
});

it('does not alert an owner of a different property', function () {
    Notification::fake();

    $owned = makeAsset(['code' => 'OWN']);
    $other = makeAsset(['code' => 'OTH']);
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($owned->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($other);
    makeMaintenanceRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('maintenance:scan-sla-breaches')->assertSuccessful();

    Notification::assertNotSentTo($owner, MaintenanceSlaBreachedNotification::class);
});
