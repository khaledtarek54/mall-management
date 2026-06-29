<?php

use App\Models\Asset;
use App\Models\User;
use App\Notifications\InvoiceOverdueOwnerNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| billing:scan-overdue-invoices — core behaviour
|--------------------------------------------------------------------------
| The command flags an overdue invoice (balance > 0, due in the past, an
| alertable status) by notifying the Jawad owner(s) of the property and
| stamping invoices.owner_overdue_notified_at. That stamp is the
| idempotency guard — it alerts each overdue invoice exactly once.
|
| Three behaviours under test:
|  1. an invoice past its due_date with a balance gets flagged overdue;
|  2. a not-yet-due invoice does NOT;
|  3. re-running is idempotent (no second alert, stamp unchanged).
*/

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

/** Attach a user as a whole/fractional owner of an asset (asset_owner pivot). */
function ownOverdueAsset(User $user, Asset $asset, int $pct = 100): void
{
    $user->ownedAssets()->attach($asset->id, ['ownership_percentage' => $pct]);
}

it('flags an invoice past its due_date with an outstanding balance as overdue', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownOverdueAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    // Stamp starts null — nothing has flagged it yet.
    expect($invoice->owner_overdue_notified_at)->toBeNull();

    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('Alerted owners on 1 of 1 overdue invoice(s).')
        ->assertExitCode(0);

    // Flagged: the owner is alerted and the idempotency stamp is written.
    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});

it('does NOT flag an invoice that is not yet due (future due_date)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownOverdueAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->addDays(7),   // still in the future
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
    // Untouched, so it can be flagged once it actually falls due.
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
});

it('does NOT flag a past-due invoice once its balance is settled', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownOverdueAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    // Past due, but fully paid: the balance > 0 guard excludes it.
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 1000,
        'balance' => 0,
    ]);

    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
});

it('is idempotent — re-running does not flag the same invoice twice', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownOverdueAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    // First run flags it.
    $this->artisan('billing:scan-overdue-invoices')->assertExitCode(0);
    $firstStamp = $invoice->refresh()->owner_overdue_notified_at;
    expect($firstStamp)->not->toBeNull();

    // Second run: the whereNull(owner_overdue_notified_at) guard skips it.
    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertExitCode(0);

    // Exactly one alert across both runs, and the stamp is unchanged.
    Notification::assertSentToTimes($owner, InvoiceOverdueOwnerNotification::class, 1);
    expect($invoice->refresh()->owner_overdue_notified_at->equalTo($firstStamp))->toBeTrue();
});
