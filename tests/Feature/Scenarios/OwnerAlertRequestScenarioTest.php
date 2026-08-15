<?php

use App\Notifications\InvoiceOverdueOwnerNotification;
use App\Notifications\TenantRequestSlaBreachedNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Feature #13 — Jawad-owner alerts, MAINTENANCE-SLA half + both commands.
|--------------------------------------------------------------------------
| Complements MaintenanceSlaOwnerAlertTest (owner happy/scoping) and
| MaintenanceScenarioTest's "SLA breach scan boundaries" describe block
| (not-yet-due, null target, owner idempotency). NET-NEW focus here:
|   - the STAFF fan-out: a manager AND an operations user assigned to the
|     asset are alerted alongside the owner (the command merges
|     AssetStaffRecipients::for(manager,operations) with ::owners);
|   - a FINISHED request (resolved/closed/cancelled) past its target is NOT
|     in OPEN_STATUSES → nobody is alerted;
|   - staff scoping: a manager assigned to a DIFFERENT asset is not alerted;
|   - --dry-run writes nothing (no notifications, no idempotency stamp);
|   - idempotency proven against the STAFF recipients (not just the owner);
|   - one cross-feature assertion that BOTH req-#4 halves (maintenance SLA +
|     invoice overdue) deliver to the SAME owner.
*/

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

// ============================================================
// STAFF + OWNER FAN-OUT — the net-new half of MNT-5
// ============================================================

it('alerts BOTH the asset staff (manager + operations) AND the owner on an open breach', function () {
    Notification::fake();

    $asset = makeAsset();

    // Property team assigned to THIS asset.
    $manager = makeUser('manager', [$asset->id]);
    $operations = makeUser('operations', [$asset->id]);

    // Jawad owner of the same asset.
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($asset);
    $req = makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($manager, TenantRequestSlaBreachedNotification::class);
    Notification::assertSentTo($operations, TenantRequestSlaBreachedNotification::class);
    Notification::assertSentTo($owner, TenantRequestSlaBreachedNotification::class);

    expect($req->refresh()->sla_breach_notified_at)->not->toBeNull();
});

it('does NOT alert a leasing/viewer teammate even when assigned to the breached asset', function () {
    Notification::fake();

    $asset = makeAsset();

    // Out-of-department roles assigned to the asset — not maintenance recipients.
    $leasing = makeUser('leasing', [$asset->id]);
    $viewer = makeUser('viewer', [$asset->id]);
    // In-department control: the manager IS a recipient.
    $manager = makeUser('manager', [$asset->id]);

    $unit = makeUnit($asset);
    makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($manager, TenantRequestSlaBreachedNotification::class);
    Notification::assertNotSentTo($leasing, TenantRequestSlaBreachedNotification::class);
    Notification::assertNotSentTo($viewer, TenantRequestSlaBreachedNotification::class);
});

// ============================================================
// STAFF SCOPING — assigned to a different asset is not alerted
// ============================================================

it('does not alert a manager assigned to a DIFFERENT asset (staff scoping)', function () {
    Notification::fake();

    $breached = makeAsset(['code' => 'BRC']);
    $other = makeAsset(['code' => 'OTH']);

    $hereManager = makeUser('manager', [$breached->id]);
    $elsewhereManager = makeUser('manager', [$other->id]);

    $unit = makeUnit($breached);
    makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($hereManager, TenantRequestSlaBreachedNotification::class);
    Notification::assertNotSentTo($elsewhereManager, TenantRequestSlaBreachedNotification::class);
});

// ============================================================
// FINISHED REQUEST PAST TARGET — not in OPEN_STATUSES → no alert
// ============================================================

it('does NOT alert when a finished request is past its target (terminal/resolved excluded)', function (string $finished) {
    Notification::fake();

    $asset = makeAsset();
    $manager = makeUser('manager', [$asset->id]);
    $operations = makeUser('operations', [$asset->id]);
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($asset);
    $req = makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => $finished,
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();

    Notification::assertNotSentTo($manager, TenantRequestSlaBreachedNotification::class);
    Notification::assertNotSentTo($operations, TenantRequestSlaBreachedNotification::class);
    Notification::assertNotSentTo($owner, TenantRequestSlaBreachedNotification::class);
    expect($req->refresh()->sla_breach_notified_at)->toBeNull();
})->with(['resolved', 'closed', 'cancelled']);

// ============================================================
// --dry-run — writes nothing (no sends, no idempotency stamp)
// ============================================================

it('--dry-run sends no notifications and leaves sla_breach_notified_at null', function () {
    Notification::fake();

    $asset = makeAsset();
    $manager = makeUser('manager', [$asset->id]);
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($asset);
    $req = makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches', ['--dry-run' => true])
        ->expectsOutputToContain('Would alert on 1 breach(es):')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($req->refresh()->sla_breach_notified_at)->toBeNull();

    // A subsequent REAL run still fires — dry-run did not consume the breach.
    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();
    Notification::assertSentTo($manager, TenantRequestSlaBreachedNotification::class);
    expect($req->refresh()->sla_breach_notified_at)->not->toBeNull();
});

// ============================================================
// IDEMPOTENCY — proven against the STAFF recipients
// ============================================================

it('alerts each staff recipient exactly once across re-runs (idempotent stamp)', function () {
    Notification::fake();

    $asset = makeAsset();
    $manager = makeUser('manager', [$asset->id]);
    $operations = makeUser('operations', [$asset->id]);

    $unit = makeUnit($asset);
    $req = makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();
    $firstStamp = $req->refresh()->sla_breach_notified_at;
    expect($firstStamp)->not->toBeNull();

    $this->artisan('requests:scan-sla-breaches')
        ->expectsOutputToContain('No new SLA breaches.')
        ->assertSuccessful();

    Notification::assertSentToTimes($manager, TenantRequestSlaBreachedNotification::class, 1);
    Notification::assertSentToTimes($operations, TenantRequestSlaBreachedNotification::class, 1);
    expect($req->refresh()->sla_breach_notified_at->equalTo($firstStamp))->toBeTrue();
});

// ============================================================
// CROSS-FEATURE — BOTH req-#4 halves reach the SAME owner
// ============================================================

it('delivers BOTH req-#4 halves (maintenance SLA + invoice overdue) to the same owner', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $unit = makeUnit($asset);

    // Half (a): an open maintenance request breaches SLA.
    $req = makeTenantRequest([
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => now()->subDay(),
    ]);

    // Half (b): a tenant is late paying an invoice on the same property.
    $lease = makeLease($unit);
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('requests:scan-sla-breaches')->assertSuccessful();
    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    // The one owner receives both distinct oversight alerts.
    Notification::assertSentTo($owner, TenantRequestSlaBreachedNotification::class);
    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);

    expect($req->refresh()->sla_breach_notified_at)->not->toBeNull()
        ->and($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});
