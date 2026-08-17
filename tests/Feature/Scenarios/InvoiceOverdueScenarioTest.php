<?php

use App\Models\Asset;
use App\Models\User;
use App\Notifications\InvoiceOverdueOwnerNotification;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Feature #13 — OVERDUE-INVOICE owner alert (billing:scan-overdue-invoices)
| EXHAUSTIVE net-new scenarios.
|--------------------------------------------------------------------------
| Complements InvoiceOverdueOwnerAlertTest.php (happy path + future/paid +
| cross-property + basic idempotency). Net-new coverage here, by case class:
|
|  HAPPY     — every alertable status (issued / partially_paid / overdue).
|  NEGATIVE  — every non-alertable status (paid / draft / cancelled /
|              credited / disputed), future-due, and balance-0.
|  BOUNDARY  — whereDate(due_date < now): due TODAY does NOT alert,
|              due YESTERDAY does. days_overdue payload at the boundary.
|  STATE     — idempotency stamp blocks a second run; --dry-run writes nothing.
|  SCOPING   — multiple owners + co-owners of ONE property are ALL alerted;
|              a property with NO owner sends nothing AND leaves the stamp
|              null (so it can alert once an owner is later attached);
|              cross-property isolation across two overdue invoices.
|  PAYLOAD   — number / days_overdue / balance fields are correct.
|
| Owner resolution is via AssetStaffRecipients::owners(), which keys purely
| off the asset_owner pivot (ownedAssets) — the spatie role is irrelevant to
| who receives the alert.
*/

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

/** Attach a user as a fractional/whole owner of an asset. */
function ownInvoiceAsset(User $user, Asset $asset, int $pct = 100): void
{
    $user->ownedAssets()->attach($asset->id, ['ownership_percentage' => $pct]);
}

// ============================================================
// HAPPY — every alertable status fires the alert
// ============================================================

it('alerts the owner for each alertable status (issued / partially_paid / overdue)', function (string $status, float $paid, float $balance) {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => $status,
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => $paid,
        'balance' => $balance,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
})->with([
    'issued' => ['issued', 0.0, 1000.0],
    'partially_paid' => ['partially_paid', 400.0, 600.0],
    'overdue' => ['overdue', 0.0, 1000.0],
]);

// ============================================================
// NEGATIVE — non-alertable statuses never fire (balance > 0, past due)
// ============================================================

it('never alerts for a non-alertable status even when overdue with a balance', function (string $status) {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => $status,
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,   // balance > 0, clearly past due — only status disqualifies it
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
    // No stamp, so if its status later flips to overdue it can still alert.
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
})->with(['paid', 'draft', 'cancelled', 'credited', 'disputed']);

it('does not alert when the balance is zero (settled, even if status is still issued)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 1000,
        'balance' => 0,   // balance > 0 guard excludes it
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
});

it('does not alert when the invoice is not yet due (future due date)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->addDays(3),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
});

// ============================================================
// BOUNDARY — whereDate(due_date < now): today excluded, yesterday included
// ============================================================

it('does NOT alert for an invoice due TODAY (whereDate due_date < now is strict)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now(),   // today — not strictly before today's date
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNotSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();
});

it('DOES alert for an invoice due YESTERDAY (the first overdue day)', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDay(),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});

// ============================================================
// PAYLOAD — number / days_overdue / balance carried correctly
// ============================================================

it('carries the correct number, days_overdue and balance in the database payload', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));

    // due 10 days ago, partially paid: balance 600 outstanding.
    $invoice = makeInvoice($lease, [
        'status' => 'partially_paid',
        'due_date' => now()->subDays(10)->startOfDay(),
        'total' => 1000,
        'paid_amount' => 400,
        'balance' => 600,
    ]);
    // number is auto-generated on create; read it back.
    $expectedNumber = $invoice->refresh()->number;

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo(
        $owner,
        InvoiceOverdueOwnerNotification::class,
        function (InvoiceOverdueOwnerNotification $n) use ($invoice, $expectedNumber, $owner) {
            // toDatabase ignores the notifiable; pass the owner for realism.
            $data = $n->toDatabase($owner);

            expect($data['type'])->toBe('invoice_overdue')
                ->and($data['invoice_id'])->toBe($invoice->id)
                ->and($data['number'])->toBe($expectedNumber)
                ->and($data['balance'])->toBe(600.0)
                ->and($data['days_overdue'])->toBe(10);

            return true;
        }
    );
});

// ============================================================
// STATE — idempotency stamp + dry-run
// ============================================================

it('blocks re-alerting on a second run via the idempotency stamp', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();
    $firstStamp = $invoice->refresh()->owner_overdue_notified_at;
    expect($firstStamp)->not->toBeNull();

    // Second run: the whereNull(owner_overdue_notified_at) guard skips it.
    $this->artisan('billing:scan-overdue-invoices')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertSuccessful();

    Notification::assertSentToTimes($owner, InvoiceOverdueOwnerNotification::class, 1);
    expect($invoice->refresh()->owner_overdue_notified_at->equalTo($firstStamp))->toBeTrue();
});

it('--dry-run sends nothing and stamps nothing', function () {
    Notification::fake();

    $asset = makeAsset();
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices --dry-run')->assertSuccessful();

    Notification::assertNothingSent();
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();

    // And a real run afterwards still alerts (dry-run left it eligible).
    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();
    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});

// ============================================================
// SCOPING — multiple owners / co-owners / no owner / cross-property
// ============================================================

it('alerts BOTH co-owners of the same property', function () {
    Notification::fake();

    $asset = makeAsset();
    $alice = makeUser('owner');
    $bob = makeUser('owner');
    ownInvoiceAsset($alice, $asset, 60);
    ownInvoiceAsset($bob, $asset, 40);

    $lease = makeLease(makeUnit($asset));
    makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($alice, InvoiceOverdueOwnerNotification::class);
    Notification::assertSentTo($bob, InvoiceOverdueOwnerNotification::class);
});

it('alerts owners regardless of which spatie role they hold (owner pivot is the source of truth)', function () {
    Notification::fake();

    $asset = makeAsset();
    // A manager who also owns the property — they are an owner by pivot, not by role.
    $managerOwner = makeUser('manager');
    ownInvoiceAsset($managerOwner, $asset);

    $lease = makeLease(makeUnit($asset));
    makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertSentTo($managerOwner, InvoiceOverdueOwnerNotification::class);
});

it('on a property with NO owner: succeeds, sends nothing, and leaves the stamp NULL so a later-added owner can still be alerted', function () {
    Notification::fake();

    $asset = makeAsset();            // no owner attached
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
        'total' => 1000,
        'paid_amount' => 0,
        'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    Notification::assertNothingSent();
    // Critically NOT stamped — empty owners must not consume the one-shot.
    expect($invoice->refresh()->owner_overdue_notified_at)->toBeNull();

    // Attach an owner, re-run: now it alerts (proves the no-op didn't burn the chance).
    $owner = makeUser('owner');
    ownInvoiceAsset($owner, $asset);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();
    Notification::assertSentTo($owner, InvoiceOverdueOwnerNotification::class);
    expect($invoice->refresh()->owner_overdue_notified_at)->not->toBeNull();
});

it('isolates owners across properties — each owner only hears about their own property', function () {
    Notification::fake();

    $assetA = makeAsset(['code' => 'AAA']);
    $assetB = makeAsset(['code' => 'BBB']);
    $ownerA = makeUser('owner');
    $ownerB = makeUser('owner');
    ownInvoiceAsset($ownerA, $assetA);
    ownInvoiceAsset($ownerB, $assetB);

    // Both properties have an overdue invoice.
    makeInvoice(makeLease(makeUnit($assetA)), [
        'status' => 'issued', 'due_date' => now()->subDays(5),
        'total' => 1000, 'paid_amount' => 0, 'balance' => 1000,
    ]);
    makeInvoice(makeLease(makeUnit($assetB)), [
        'status' => 'issued', 'due_date' => now()->subDays(5),
        'total' => 1000, 'paid_amount' => 0, 'balance' => 1000,
    ]);

    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    // Each owner gets exactly one alert (their own), never the other's.
    Notification::assertSentToTimes($ownerA, InvoiceOverdueOwnerNotification::class, 1);
    Notification::assertSentToTimes($ownerB, InvoiceOverdueOwnerNotification::class, 1);
});
