<?php

use App\Models\InvoiceItem;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Notifications\LateFeeAppliedNotification;
use App\Services\LateFeeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 2a — tenant-facing billing reminders (email + bell + mobile push)
|--------------------------------------------------------------------------
| Two new tenant notifications, both carrying the 'push' channel:
|  - LateFeeAppliedNotification  → fired by LateFeeService when a fee is added
|  - InvoiceOverdueTenantNotification → fired by billing:remind-overdue-tenants
|    (idempotent via invoices.tenant_overdue_notified_at, independent of the
|    owner overdue alert).
*/

function overdueInvoiceForFee(array $attrs = [])
{
    $lease = makeLease(makeUnit(makeAsset()));

    return makeInvoice($lease, array_merge([
        'status' => 'issued',
        'due_date' => now()->subDays(30), // well past the 7-day grace
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
    ], $attrs));
}

// --- Late fee applied ---------------------------------------------------

it('notifies the tenant when a late fee is applied', function () {
    Notification::fake();
    $invoice = overdueInvoiceForFee();

    $stats = app(LateFeeService::class)->runForToday();

    expect($stats['applied'])->toBe(1);
    Notification::assertSentTo(
        $invoice->tenant,
        LateFeeAppliedNotification::class,
        // Both invoices, and specifically the right way round: the overdue one is what the tenant
        // recognises, the fee invoice is the new charge raised against it.
        fn (LateFeeAppliedNotification $n) => $n->overdueInvoice->is($invoice)
            && $n->feeInvoice->is($invoice->fresh()->lateFeeInvoice),
    );
});

it('does not notify the tenant when the invoice is skipped (fee already applied)', function () {
    Notification::fake();
    $invoice = overdueInvoiceForFee();

    app(LateFeeService::class)->runForToday(); // applies + notifies once
    app(LateFeeService::class)->runForToday(); // idempotent skip — no second notify

    Notification::assertSentToTimes($invoice->tenant, LateFeeAppliedNotification::class, 1);
});

it('actually writes the notification rows through the real queue path (dispatched inside applyTo tx)', function () {
    // No Notification::fake() here — on the sync test queue the ShouldQueue
    // notification runs INLINE inside applyTo's transaction, exercising the real
    // in-transaction dispatch (the faked tests above short-circuit that path).
    $lease = makeLease(makeUnit(makeAsset()));
    $tenant = $lease->tenant;
    makeTenantUser($tenant); // notifyPortal fans to the Tenant + this portal login
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(30),
        'total' => 11400,
        'paid_amount' => 0,
        'balance' => 11400,
    ]);

    $stats = app(LateFeeService::class)->runForToday();

    expect($stats['applied'])->toBe(1)
        ->and(lateFeeItems($invoice)->exists())->toBeTrue()
        // Committed with the fee: a bell row for the Tenant AND the portal login.
        ->and(DB::table('notifications')->where('data', 'like', '%late_fee_applied%')->count())->toBe(2);
});

it('LateFeeAppliedNotification routes through mail, database and push', function () {
    $invoice = overdueInvoiceForFee();
    $via = (new LateFeeAppliedNotification($invoice, $invoice))->via($invoice->tenant);

    expect($via)->toEqualCanonicalizing(['mail', 'database', 'push']);
});

it('LateFeeAppliedNotification payload names BOTH invoices', function () {
    // Two documents since FS-27: the overdue invoice the tenant recognises, and the fee invoice
    // they have never seen. The payload deep-links to the FIRST — that is the thing to pay — and
    // carries the second alongside, so a tenant is never shown a charge with no bill behind it.
    $overdue = overdueInvoiceForFee(['balance' => 5000]);
    $feeInvoice = overdueInvoiceForFee(['balance' => 100, 'total' => 100]);
    InvoiceItem::create([
        'invoice_id' => $feeInvoice->id,
        'description' => 'Late fee',
        'type' => 'late_fee',
        'amount' => 100,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 100,
    ]);

    $payload = (new LateFeeAppliedNotification($feeInvoice, $overdue))->toDatabase($overdue->tenant);

    expect($payload['type'])->toBe('late_fee_applied')
        ->and($payload['fee'])->toBe(100.0)
        ->and($payload['invoice_id'])->toBe($overdue->id)
        ->and($payload['balance'])->toBe(5000.0)
        ->and($payload['fee_invoice_id'])->toBe($feeInvoice->id)
        ->and($payload['format'])->toBe('filament');
});

// --- Overdue tenant reminder command -----------------------------------

it('reminds the tenant about an overdue invoice and stamps tenant_overdue_notified_at', function () {
    Notification::fake();
    $invoice = overdueInvoiceForFee();

    expect($invoice->tenant_overdue_notified_at)->toBeNull();

    $this->artisan('billing:remind-overdue-tenants')
        ->expectsOutputToContain('Reminded tenants on 1 of 1 overdue invoice(s).')
        ->assertExitCode(0);

    Notification::assertSentTo($invoice->tenant, InvoiceOverdueTenantNotification::class);
    $fresh = $invoice->refresh();
    expect($fresh->tenant_overdue_notified_at)->not->toBeNull()
        // The owner alert is tracked on a *separate* stamp — untouched here.
        ->and($fresh->owner_overdue_notified_at)->toBeNull();
});

it('is idempotent — re-running does not remind the same tenant twice', function () {
    Notification::fake();
    $invoice = overdueInvoiceForFee();

    $this->artisan('billing:remind-overdue-tenants')->assertExitCode(0);
    $firstStamp = $invoice->refresh()->tenant_overdue_notified_at;

    $this->artisan('billing:remind-overdue-tenants')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertExitCode(0);

    Notification::assertSentToTimes($invoice->tenant, InvoiceOverdueTenantNotification::class, 1);
    expect($invoice->refresh()->tenant_overdue_notified_at->equalTo($firstStamp))->toBeTrue();
});

it('does NOT remind the tenant about a not-yet-due invoice', function () {
    Notification::fake();
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, ['status' => 'issued', 'due_date' => now()->addDays(7), 'balance' => 11400]);

    $this->artisan('billing:remind-overdue-tenants')
        ->expectsOutputToContain('No new overdue invoices.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
    expect($invoice->refresh()->tenant_overdue_notified_at)->toBeNull();
});
