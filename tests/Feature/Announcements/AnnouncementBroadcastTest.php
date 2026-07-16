<?php

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Notifications\AnnouncementNotification;
use App\Services\SendAnnouncementAction;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| Operator announcements — broadcast fan-out + RBAC
|--------------------------------------------------------------------------
| An announcement targets ONE property and reaches every ACTIVE tenant there
| (in-app bell + mobile push, no email), then stamps sent_at + recipients_count.
*/

// --- broadcast fan-out --------------------------------------------------

it('broadcasts to every active tenant of the target property and stamps the send', function () {
    Notification::fake();

    $assetA = makeAsset();
    $tenantA = makeTenant();
    makeLease(makeUnit($assetA), $tenantA); // active in A

    // A tenant in another property — must NOT receive it.
    $tenantB = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenantB);

    // A tenant in A but with a terminated lease — must NOT receive it.
    $tenantInactive = makeTenant();
    makeLease(makeUnit($assetA), $tenantInactive, ['status' => 'terminated']);

    $announcement = Announcement::create([
        'asset_id' => $assetA->id, 'title' => 'Roof works', 'body' => 'Expect some noise this week.',
    ]);

    $count = app(SendAnnouncementAction::class)->handle($announcement);

    expect($count)->toBe(1);
    Notification::assertSentTo($tenantA, AnnouncementNotification::class);
    Notification::assertNotSentTo($tenantB, AnnouncementNotification::class);
    Notification::assertNotSentTo($tenantInactive, AnnouncementNotification::class);

    $fresh = $announcement->refresh();
    expect($fresh->sent_at)->not->toBeNull()
        ->and($fresh->recipients_count)->toBe(1);
});

it('does not re-broadcast an already-sent announcement', function () {
    Notification::fake();

    $asset = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant);
    $announcement = Announcement::create(['asset_id' => $asset->id, 'title' => 'Hi', 'body' => 'Body']);

    app(SendAnnouncementAction::class)->handle($announcement);
    $stamp = $announcement->refresh()->sent_at;

    app(SendAnnouncementAction::class)->handle($announcement->refresh());

    Notification::assertSentToTimes($tenant, AnnouncementNotification::class, 1);
    expect($announcement->refresh()->sent_at->equalTo($stamp))->toBeTrue();
});

it('routes announcements through the bell + push only (no email)', function () {
    $announcement = new Announcement(['title' => 'Hi', 'body' => 'Body']);
    $via = (new AnnouncementNotification($announcement))->via(makeTenant());

    expect($via)->toEqualCanonicalizing(['database', 'push']);
});

it('writes the bell rows for the tenant + portal logins through the real path', function () {
    $asset = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant);
    makeTenantUser($tenant); // Tenant + 1 portal login = 2 bell rows

    $announcement = Announcement::create(['asset_id' => $asset->id, 'title' => 'Hi', 'body' => 'Body']);
    app(SendAnnouncementAction::class)->handle($announcement);

    expect(DB::table('notifications')->where('data', 'like', '%"type":"announcement"%')->count())->toBe(2);
});

it('reaches a tenant whose ACTIVE lease covers a unit in the property as an ADDITIONAL (non-master) unit', function () {
    Notification::fake();

    // Master unit in property A, additional unit in property B. leases.unit_id is
    // the MASTER only, so a `unit`-based query would miss this tenant for B.
    $assetA = makeAsset();
    $assetB = makeAsset();
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($assetA), $tenant);
    $unitB = makeUnit($assetB);
    $lease->syncUnits([$lease->unit_id, $unitB->id], $lease->unit_id);

    $announcement = Announcement::create(['asset_id' => $assetB->id, 'title' => 'B works', 'body' => 'Body']);
    $count = app(SendAnnouncementAction::class)->handle($announcement);

    expect($count)->toBe(1);
    Notification::assertSentTo($tenant, AnnouncementNotification::class);
});

// --- property isolation (the write guard actually runs) -------------------

it('rejects composing into a property outside the operator\'s visible set', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $mine = makeAsset(['code' => 'ANA']);
    $theirs = makeAsset(['code' => 'ANB']);

    // A property-restricted operator, assigned only to $mine.
    $this->actingAs(makeUser('marketing', [$mine->id]));

    // In scope → passes. (This is the guard CreateAnnouncement calls.)
    AnnouncementResource::assertAssetInScope($mine->id);
    expect(true)->toBeTrue();

    // Out of scope → 403, so a tampered form value can't broadcast into another mall.
    expect(fn () => AnnouncementResource::assertAssetInScope($theirs->id))
        ->toThrow(HttpException::class);
});

it('does NOT use Filament tenancy ownership (its creating hook would clobber the chosen property)', function () {
    // Regression guard for the All-Properties silent-zero-recipients bug: with
    // isScopedToTenant() true, Filament force-associates asset_id with the current
    // panel tenant on create — which in All-Properties mode is the ALL pseudo-asset,
    // discarding the operator's chosen property and broadcasting to nobody.
    expect(AnnouncementResource::isScopedToTenant())->toBeFalse();
});

// --- RBAC ---------------------------------------------------------------

it('gates who may compose announcements', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->actingAs(makeUser('marketing'));
    expect(AnnouncementResource::canCreate())->toBeTrue()
        ->and(AnnouncementResource::canViewAny())->toBeTrue();

    $this->actingAs(makeUser('leasing'));
    expect(AnnouncementResource::canCreate())->toBeFalse();

    // Managers get create (all non-delete); viewers get read-only.
    $this->actingAs(makeUser('manager'));
    expect(AnnouncementResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('viewer'));
    expect(AnnouncementResource::canViewAny())->toBeTrue()
        ->and(AnnouncementResource::canCreate())->toBeFalse();
});
