<?php

/*
|--------------------------------------------------------------------------
| Notification Flows — NET-NEW scenarios
|--------------------------------------------------------------------------
| Sibling suites (tests/Feature/Notifications/*) already assert the headline
| "did the notification fire on the Tenant" question for each event, plus the
| operator-side triage fan-out and the no-fire negatives. This file fills the
| gaps those leave open, all of which the module brief calls out explicitly:
|
|   1. Tenant::notifyPortal FAN-OUT — every tenant-facing event must reach the
|      Tenant record AND each of the tenant's portal TenantUser logins (the web
|      bell reads TenantUser notifications). No sibling test touches the portal
|      users; they only assert assertSentTo($tenant, ...).
|   2. SCOPING — an unrelated tenant (and its portal users) must NOT be
|      notified by another tenant's billing / payment / maintenance / sales
|      events.
|   3. The toDatabase() PAYLOAD — title / body / type / format=filament /
|      color — for each notification, verified against the en translation
|      catalogue. Nothing else asserts the payload shape.
|   4. Recipient ROUTING + payload on the maintenance comment notification,
|      whose via()/toDatabase() branch on Tenant-vs-staff notifiable.
|
| These are pure flow/recipient/payload assertions — they do NOT re-test the
| billing math, the lock math, or the transition legality (covered elsewhere).
*/

use App\Models\Charge;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Notifications\InvoiceIssuedNotification;
use App\Notifications\MaintenanceCommentAddedNotification;
use App\Notifications\MaintenanceStatusChangedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Services\MaintenanceRequestService;
use App\Services\MonthlyBillingService;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant(['name' => 'Haya Cafe']);

    // Two portal logins for the tenant — the fan-out must reach BOTH.
    $this->portalA = makeTenantUser($this->tenant, isAdmin: true);
    $this->portalB = makeTenantUser($this->tenant, isAdmin: false);

    // A completely unrelated tenant + portal user used for the scoping checks.
    $this->otherTenant = makeTenant(['name' => 'Rival Co']);
    $this->otherPortal = makeTenantUser($this->otherTenant, isAdmin: true);

    $this->operator = makeUser('manager', [$this->asset->id]);
});

/** A billable active lease + a single active base-rent charge for $this->tenant. */
function notifLease(): \App\Models\Lease
{
    $lease = makeLease(test()->unit, test()->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 20000,
        'payment_terms_days' => 7,
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 20000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    return $lease;
}

// ============================================================================
// INVOICE ISSUED — notifyPortal fan-out + scoping + payload
// ============================================================================

it('billing fans the invoice-issued notification to the tenant AND every portal user', function () {
    Notification::fake();
    $lease = notifLease();

    app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    // The Tenant record itself (mobile API surface).
    Notification::assertSentTo($this->tenant, InvoiceIssuedNotification::class);

    // BOTH portal logins (the web bell surface) — this is the notifyPortal fan-out.
    Notification::assertSentTo($this->portalA, InvoiceIssuedNotification::class);
    Notification::assertSentTo($this->portalB, InvoiceIssuedNotification::class);

    // Exactly once each — no duplicate fan-out.
    Notification::assertSentToTimes($this->tenant, InvoiceIssuedNotification::class, 1);
    Notification::assertSentToTimes($this->portalA, InvoiceIssuedNotification::class, 1);
    Notification::assertSentToTimes($this->portalB, InvoiceIssuedNotification::class, 1);
});

it('does NOT notify an unrelated tenant or its portal user when another tenant is billed', function () {
    Notification::fake();
    $lease = notifLease();

    app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    Notification::assertNotSentTo($this->otherTenant, InvoiceIssuedNotification::class);
    Notification::assertNotSentTo($this->otherPortal, InvoiceIssuedNotification::class);
});

it('the invoice-issued toDatabase payload carries the filament-tagged title/body for the bell', function () {
    Notification::fake();
    $lease = notifLease();

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];

    // Build the payload the way Filament's bell would persist it for a portal user.
    $payload = (new InvoiceIssuedNotification($invoice))->toDatabase($this->portalA);

    expect($payload['type'])->toBe('invoice_issued')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['title'])->toBe('New invoice')
        ->and($payload['invoice_number'])->toBe($invoice->number)
        ->and($payload['body'])->toBe("Invoice {$invoice->number} for EGP 20,000.00 has been issued. The PDF is attached.")
        ->and($payload['total'])->toBe(20000.0)
        ->and($payload['color'])->toBe('primary')
        ->and($payload['duration'])->toBe('persistent');
});

it('routes the invoice-issued notification over both the mail and database channels', function () {
    $invoice = makeInvoice(notifLease());

    expect((new InvoiceIssuedNotification($invoice))->via($this->tenant))
        ->toBe(['mail', 'database']);
});

// ============================================================================
// PAYMENT RECEIVED — notifyPortal fan-out + scoping + payload
// ============================================================================

/** Capture a payment fully allocated to a fresh issued invoice on $this->tenant. */
function notifCapturePayment(\App\Models\Lease $lease, float $amount): Payment
{
    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'total' => $amount,
        'balance' => $amount,
        'tenant_id' => $lease->tenant_id,
    ]);

    $payment = Payment::create([
        'tenant_id' => $lease->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->sync([$invoice->id => ['allocated_amount' => $amount]]);
    $payment->update(['status' => 'captured']);

    return $payment->refresh();
}

it('a captured payment fans the receipt notification to the tenant AND every portal user', function () {
    Notification::fake();
    $lease = notifLease();

    notifCapturePayment($lease, 11400);

    Notification::assertSentTo($this->tenant, PaymentReceivedNotification::class);
    Notification::assertSentTo($this->portalA, PaymentReceivedNotification::class);
    Notification::assertSentTo($this->portalB, PaymentReceivedNotification::class);
    Notification::assertSentToTimes($this->portalB, PaymentReceivedNotification::class, 1);
});

it('does NOT notify an unrelated tenant or its portal user on another tenant payment', function () {
    Notification::fake();
    $lease = notifLease();

    notifCapturePayment($lease, 9000);

    Notification::assertNotSentTo($this->otherTenant, PaymentReceivedNotification::class);
    Notification::assertNotSentTo($this->otherPortal, PaymentReceivedNotification::class);
});

it('the payment-received toDatabase payload carries the success-tagged bell entry', function () {
    Notification::fake();
    $lease = notifLease();
    $payment = notifCapturePayment($lease, 5000);
    $payment->load('invoices');
    $invoiceNumbers = $payment->invoices->pluck('number')->implode(', ');

    $payload = (new PaymentReceivedNotification($payment))->toDatabase($this->portalA);

    expect($payload['type'])->toBe('payment_received')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['title'])->toBe('Payment received')
        ->and($payload['body'])->toBe("EGP 5,000.00 allocated to {$invoiceNumbers}.")
        ->and($payload['amount'])->toBe(5000.0)
        ->and($payload['method'])->toBe('bank_transfer')
        ->and($payload['color'])->toBe('success');
});

// ============================================================================
// MAINTENANCE STATUS CHANGE — notifyPortal fan-out + scoping + payload
// ============================================================================

function notifMaintenanceRequest(array $attrs = []): MaintenanceRequest
{
    return MaintenanceRequest::create(array_merge([
        'reference' => 'MR-' . uniqid(),
        'tenant_id' => test()->tenant->id,
        'unit_id' => test()->unit->id,
        'title' => 'AC not cooling',
        'description' => 'Storefront is hot',
        'status' => 'submitted',
        'priority' => 'high',
        'category' => 'hvac',
        'submitted_at' => now(),
    ], $attrs));
}

it('a maintenance status change fans to the tenant AND every portal user, not an unrelated tenant', function () {
    Notification::fake();
    $request = notifMaintenanceRequest();

    app(MaintenanceRequestService::class)->transition($request, 'acknowledged');

    Notification::assertSentTo($this->tenant, MaintenanceStatusChangedNotification::class);
    Notification::assertSentTo($this->portalA, MaintenanceStatusChangedNotification::class);
    Notification::assertSentTo($this->portalB, MaintenanceStatusChangedNotification::class);

    // Scoping: the unrelated tenant's surfaces stay silent.
    Notification::assertNotSentTo($this->otherTenant, MaintenanceStatusChangedNotification::class);
    Notification::assertNotSentTo($this->otherPortal, MaintenanceStatusChangedNotification::class);
});

it('the maintenance status toDatabase payload reflects the new status with the right title/color/icon', function () {
    $request = notifMaintenanceRequest(['status' => 'submitted']);

    // Drive a real transition so the notification sees the post-update record.
    app(MaintenanceRequestService::class)->transition($request, 'in_progress');
    $request->refresh();

    $payload = (new MaintenanceStatusChangedNotification($request, 'submitted'))
        ->toDatabase($this->portalA);

    expect($payload['type'])->toBe('maintenance_status_changed')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['status'])->toBe('in_progress')
        ->and($payload['reference'])->toBe($request->reference)
        ->and($payload['title'])->toBe("Maintenance update · {$request->reference}")
        // in_progress maps to the warning colour + wrench-screwdriver icon.
        ->and($payload['color'])->toBe('warning')
        ->and($payload['icon'])->toBe('heroicon-o-wrench-screwdriver');
});

it('the maintenance status body humanises the new status label', function () {
    $request = notifMaintenanceRequest(['status' => 'submitted']);
    app(MaintenanceRequestService::class)->transition($request, 'in_progress');
    $request->refresh();

    $payload = (new MaintenanceStatusChangedNotification($request, 'submitted'))
        ->toDatabase($this->portalA);

    // The bell body should read the human "In Progress" label, not a raw key.
    expect($payload['body'])->toBe('"AC not cooling" is now In Progress.');
});

it('a resolved transition payload flips to the success colour + check icon', function () {
    // submitted → in_progress → resolved is the legal route to resolved.
    $request = notifMaintenanceRequest(['status' => 'in_progress']);

    app(MaintenanceRequestService::class)
        ->transition($request, 'resolved', ['resolution_notes' => 'Compressor replaced']);
    $request->refresh();

    $payload = (new MaintenanceStatusChangedNotification($request, 'in_progress'))
        ->toDatabase($this->tenant);

    expect($payload['status'])->toBe('resolved')
        ->and($payload['color'])->toBe('success')
        ->and($payload['icon'])->toBe('heroicon-o-check-circle');
});

// ============================================================================
// MAINTENANCE COMMENT — recipient routing differs by author + payload shape
// ============================================================================

it('a STAFF comment fans to the tenant AND every portal user (mail+database surface)', function () {
    Notification::fake();
    $request = notifMaintenanceRequest(['status' => 'in_progress']);

    app(MaintenanceRequestService::class)
        ->comment($request, $this->operator, 'On our way', isInternal: false);

    Notification::assertSentTo($this->tenant, MaintenanceCommentAddedNotification::class);
    Notification::assertSentTo($this->portalA, MaintenanceCommentAddedNotification::class);
    Notification::assertSentTo($this->portalB, MaintenanceCommentAddedNotification::class);

    // The operator who authored it is not pinged about their own comment.
    Notification::assertNotSentTo($this->operator, MaintenanceCommentAddedNotification::class);
    // And an unrelated tenant is never in scope.
    Notification::assertNotSentTo($this->otherTenant, MaintenanceCommentAddedNotification::class);
});

it('the staff-comment toDatabase payload (tenant recipient) carries the tenant-facing copy', function () {
    $request = notifMaintenanceRequest(['status' => 'in_progress']);
    $comment = app(MaintenanceRequestService::class)
        ->comment($request, $this->operator, 'Technician dispatched', isInternal: false);

    // Tenant-facing branch of toDatabase().
    $payload = (new MaintenanceCommentAddedNotification($request, $comment))
        ->toDatabase($this->tenant);

    expect($payload['type'])->toBe('maintenance_comment_added')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['title'])->toBe("Maintenance update · {$request->reference}")
        ->and($payload['body'])->toBe('New comment on "AC not cooling".')
        ->and($payload['color'])->toBe('primary');

    // via() for a Tenant uses both channels.
    expect((new MaintenanceCommentAddedNotification($request, $comment))->via($this->tenant))
        ->toBe(['mail', 'database']);
});

it('a TENANT comment notifies staff over database-only, with the staff-facing payload', function () {
    Notification::fake();
    $request = notifMaintenanceRequest(['status' => 'submitted']);

    app(MaintenanceRequestService::class)
        ->comment($request, $this->tenant, 'Any update?', isInternal: false);

    // Staff bell entry — the assigned operator receives it.
    Notification::assertSentTo($this->operator, MaintenanceCommentAddedNotification::class);
    // The tenant who authored it is NOT pinged back (their own comment).
    Notification::assertNotSentTo($this->tenant, MaintenanceCommentAddedNotification::class);
    Notification::assertNotSentTo($this->portalA, MaintenanceCommentAddedNotification::class);

    // Staff branch: database-only channel + staff-facing copy.
    $comment = $request->comments()->latest('id')->first();
    $notification = new MaintenanceCommentAddedNotification($request, $comment);

    expect($notification->via($this->operator))->toBe(['database']);

    $payload = $notification->toDatabase($this->operator);
    expect($payload['type'])->toBe('maintenance_comment_added')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['title'])->toBe('New tenant comment')
        ->and($payload['color'])->toBe('info')
        ->and($payload['body'])->toContain('Haya Cafe')
        ->and($payload['body'])->toContain($request->reference)
        ->and($payload['body'])->toContain('Any update?');
});

it('an internal staff note fans out to NO ONE on either side', function () {
    Notification::fake();
    $request = notifMaintenanceRequest(['status' => 'in_progress']);

    app(MaintenanceRequestService::class)
        ->comment($request, $this->operator, 'Waiting on parts', isInternal: true);

    Notification::assertNothingSent();
});

// ============================================================================
// SALES DECLARATION LOCKED — notifyPortal fan-out + scoping + payload
// ============================================================================

/** A submitted percentage-rent declaration on $this->tenant's lease. */
function notifDeclaration(float $sales): TenantSalesDeclaration
{
    $lease = makeLease(test()->unit, test()->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5.0,
        'base_rent_monthly' => 10000,
    ]);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => test()->tenant::class,
        'declared_by_id' => test()->tenant->id,
    ]);
}

it('locking a declaration fans the notification to the tenant AND every portal user, not an unrelated tenant', function () {
    Notification::fake();
    $declaration = notifDeclaration(300000);

    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    Notification::assertSentTo($this->tenant, SalesDeclarationLockedNotification::class);
    Notification::assertSentTo($this->portalA, SalesDeclarationLockedNotification::class);
    Notification::assertSentTo($this->portalB, SalesDeclarationLockedNotification::class);

    Notification::assertNotSentTo($this->otherTenant, SalesDeclarationLockedNotification::class);
    Notification::assertNotSentTo($this->otherPortal, SalesDeclarationLockedNotification::class);
});

it('locks notify even when ZERO percentage rent is owed (under-threshold is useful news)', function () {
    Notification::fake();
    // 80,000 sales < 100,000 threshold → owes 0, but the lock still notifies.
    $declaration = notifDeclaration(80000);

    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);

    $declaration->refresh();
    expect((float) $declaration->calculated_percentage_rent)->toBe(0.0);

    Notification::assertSentTo($this->tenant, SalesDeclarationLockedNotification::class);
    Notification::assertSentTo($this->portalA, SalesDeclarationLockedNotification::class);
});

it('the sales-locked toDatabase payload carries the period, owed amount and warning colour', function () {
    // (300000 - 100000) * 5% = 10,000 owed.
    $declaration = notifDeclaration(300000);
    app(PercentageRentCalculationService::class)->lock($declaration, $this->operator);
    $declaration->refresh();

    $payload = (new SalesDeclarationLockedNotification($declaration))->toDatabase($this->portalA);

    expect($payload['type'])->toBe('sales_declaration_locked')
        ->and($payload['format'])->toBe('filament')
        ->and($payload['title'])->toBe('Sales declaration locked')
        ->and($payload['period'])->toBe('Apr 2026')
        ->and($payload['amount'])->toBe(10000.0)
        ->and($payload['body'])->toBe('Apr 2026 · percentage rent EGP 10,000.00.')
        ->and($payload['color'])->toBe('warning')
        ->and($payload['icon'])->toBe('heroicon-o-lock-closed');
});

// ============================================================================
// FAN-OUT COUNT — a tenant with N portal users yields N + 1 recipients
// ============================================================================

it('notifyPortal reaches the Tenant once plus exactly one notification per portal user', function () {
    Notification::fake();

    // Add a third portal user so the count is unambiguous (3 users now).
    $portalC = TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Third login',
        'email' => 'third-' . uniqid() . '@test.local',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $lease = notifLease();
    app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    foreach ([$this->tenant, $this->portalA, $this->portalB, $portalC] as $recipient) {
        Notification::assertSentToTimes($recipient, InvoiceIssuedNotification::class, 1);
    }

    // The unrelated tenant's user is still untouched.
    Notification::assertNotSentTo($this->otherPortal, InvoiceIssuedNotification::class);
});
