<?php

use App\Models\Invoice;
use App\Notifications\InvoiceOverdueTenantNotification;
use App\Services\SendInvoiceToTenantService;
use App\Settings\BillingSettings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The collections cluster (1A-16 · UX5-09): chasing a tenant is a LADDER, and an invoice can be sent.
 *
 * Two gaps, one workflow — getting paid. `billing:remind-overdue-tenants` filtered on a null stamp
 * and set it, so **every overdue invoice chased its tenant exactly once, for its whole life**: a
 * tenant three months behind had been written to as often as one three days behind, and nothing
 * recorded how many times anyone had been asked. And `InvoiceIssuedNotification` was dispatched from
 * `MonthlyBillingService` alone, so an invoice raised by any other path notified nobody, with no way
 * to send or re-send it — the daily *"I never received it"* call ended in a hand-attached PDF.
 *
 * The ladder ships **OFF** (`dunning_followup_days = 0`), so the first test here is the one that
 * matters most on deploy day: nothing changes until an operator says so.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    makeTenantUser($this->tenant);

    Notification::fake();
});

function overdueInvoice(array $attrs = []): Invoice
{
    return makeInvoice(test()->lease, array_merge([
        'status' => 'issued',
        'issue_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(30)->toDateString(),
    ], $attrs));
}

function setDunning(int $followUpDays, int $maxNotices = 3): void
{
    $settings = app(BillingSettings::class);
    $settings->dunning_followup_days = $followUpDays;
    $settings->dunning_max_notices = $maxNotices;
    $settings->save();
}

// ─────────────────────────────── the ladder ───────────────────────────────

it('still chases exactly once when no cadence is configured', function () {
    setDunning(0);
    $invoice = overdueInvoice();

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
    expect($invoice->fresh()->dunning_level)->toBe(1);

    // Time passes and it is still unpaid — under the shipped default, nothing more is sent. This is
    // the assertion that makes the feature safe to deploy on a live install.
    $this->travel(90)->days();
    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    expect($invoice->fresh()->dunning_level)->toBe(1);
    Notification::assertSentToTimes($this->tenant, InvoiceOverdueTenantNotification::class, 1);
});

it('chases again once the cadence has elapsed', function () {
    setDunning(14);
    $invoice = overdueInvoice();

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
    expect($invoice->fresh()->dunning_level)->toBe(1);

    // Too soon — the cadence is the whole point, so a daily sweep must not mean a daily email.
    $this->travel(13)->days();
    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
    expect($invoice->fresh()->dunning_level)->toBe(1);

    $this->travel(2)->days();
    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
    expect($invoice->fresh()->dunning_level)->toBe(2);
});

it('stops at the ceiling rather than chasing for ever', function () {
    setDunning(7, maxNotices: 3);
    $invoice = overdueInvoice();

    // Six sweeps a week apart: the ladder should run 1 → 2 → 3 and then stop.
    for ($i = 0; $i < 6; $i++) {
        $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
        $this->travel(8)->days();
    }

    expect($invoice->fresh()->dunning_level)->toBe(3);
    Notification::assertSentToTimes($this->tenant, InvoiceOverdueTenantNotification::class, 3);
});

it('marks the last notice as final, and only the last', function () {
    setDunning(7, maxNotices: 2);
    overdueInvoice();

    $finals = [];
    for ($i = 0; $i < 3; $i++) {
        $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();
        $this->travel(8)->days();
    }

    Notification::assertSentTo($this->tenant, InvoiceOverdueTenantNotification::class,
        function (InvoiceOverdueTenantNotification $n) use (&$finals) {
            $finals[$n->notice] = $n->isFinal;

            return true;
        });

    expect($finals)->toBe([1 => false, 2 => true]);
});

it('never chases an invoice that has been paid', function () {
    setDunning(7);
    $invoice = overdueInvoice();

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    $invoice->forceFill(['balance' => 0, 'paid_amount' => $invoice->total, 'status' => 'paid'])->save();
    $this->travel(30)->days();
    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    expect($invoice->fresh()->dunning_level)->toBe(1);
});

it('never chases an invoice that is not yet due', function () {
    setDunning(7);
    $future = makeInvoice($this->lease, ['due_date' => now()->addDays(5)->toDateString()]);

    $this->artisan('billing:remind-overdue-tenants')->assertSuccessful();

    expect($future->fresh()->dunning_level)->toBe(0);
    Notification::assertNothingSentTo($this->tenant);
});

// ────────────────────────── sending the invoice itself ──────────────────────────

it('sends an invoice to its tenant and records when', function () {
    $invoice = makeInvoice($this->lease);

    expect($invoice->tenant_notified_at)->toBeNull();

    $sent = app(SendInvoiceToTenantService::class)->send($invoice);

    expect($sent)->toBeTrue()
        ->and($invoice->fresh()->tenant_notified_at)->not->toBeNull();
});

it('refuses to send a draft — a draft is not a document the tenant may see', function () {
    $draft = makeInvoice($this->lease, ['status' => 'draft']);

    expect(fn () => app(SendInvoiceToTenantService::class)->send($draft))
        ->toThrow(DomainException::class);

    expect($draft->fresh()->tenant_notified_at)->toBeNull();
});

/**
 * The re-send is the answer to "I never received it", so it must be allowed — and must move the
 * stamp, because the stamp is what the next such call is settled against.
 */
it('allows a re-send and moves the stamp forward', function () {
    $invoice = makeInvoice($this->lease);

    app(SendInvoiceToTenantService::class)->send($invoice);
    $first = $invoice->fresh()->tenant_notified_at;

    $this->travel(2)->days();
    app(SendInvoiceToTenantService::class)->send($invoice->fresh());

    expect($invoice->fresh()->tenant_notified_at->greaterThan($first))->toBeTrue();
});
