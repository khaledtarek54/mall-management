<?php

use App\Notifications\InvoiceOverdueOwnerNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('alerts the property owner when an invoice is overdue (late payment)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);

    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});

it('does not alert for a not-yet-due or already-paid invoice', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);
    $lease = makeLease(makeUnit($asset));

    makeInvoice($lease, [   // future due date
        'status' => 'issued', 'due_date' => now()->addDays(5),
        'total' => 1000, 'paid_amount' => 0, 'balance' => 1000,
    ]);
    makeInvoice($lease, [   // fully paid
        'status' => 'paid', 'due_date' => now()->subDays(5),
        'total' => 1000, 'paid_amount' => 1000, 'balance' => 0,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
});

it('does not alert an owner of a different property', function () {
    Notification::fake();

    $owned = makeAsset(['code' => 'OWN']);
    $other = makeAsset(['code' => 'OTH']);
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($owned->id, ['ownership_percentage' => 100]);

    $lease = makeLease(makeUnit($other));
    makeInvoice($lease, [
        'status' => 'issued', 'due_date' => now()->subDays(5),
        'total' => 1000, 'paid_amount' => 0, 'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
});

it('is idempotent — each overdue invoice alerts the owner only once', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($asset->id, ['ownership_percentage' => 100]);
    $lease = makeLease(makeUnit($asset));
    makeInvoice($lease, [
        'status' => 'issued', 'due_date' => now()->subDays(5),
        'total' => 1000, 'paid_amount' => 0, 'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();
    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();   // second run

    Notification::assertSentToTimes($owner, InvoiceOverdueOwnerNotification::class, 1);
});
