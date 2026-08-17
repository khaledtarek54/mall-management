<?php

/*
|--------------------------------------------------------------------------
| Terminating a lease must not cancel revenue it already earned (2026-08-17)
|--------------------------------------------------------------------------
| `cancel_open_invoices` cancelled EVERY fully-unpaid open invoice on the lease, whatever period it
| covered. On a system that bills IN ADVANCE that destroys earned revenue — and step 5, the unearned
| credit, then had nothing left to credit. The two were not merely ordered wrongly: the first made
| the second unreachable.
|
| Found by running the Chapter 8 exercise on real data. A quarterly lease terminating mid-quarter:
|
|     Oct–Dec quarterly   253,260   → cancelled, though 126,630 was earned by 15 November
|     October % rent       70,000   → cancelled, though October is ENTIRELY in the past
|     November % rent     140,000   → cancelled
|
| 463,260 of receivables wiped. The tenant occupied the space, traded from it, and owed nothing.
|
| The rule is the PERIOD, not the balance:
|   starts after the termination  → nothing earned  → cancel
|   straddles it                  → partly earned   → leave; the unearned credit handles it
|   ends before it                → fully earned    → leave it owing
*/

use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->svc = app(LeaseTerminationService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('leaves an invoice for a period the tenant actually occupied', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
    ]);

    // October — entirely before the termination. Every piastre of it is earned.
    $earned = makeInvoice($lease, [
        'status' => 'issued', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'total' => 70000, 'balance' => 70000, 'paid_amount' => 0,
    ]);

    $this->svc->terminate($lease, [
        'termination_date' => '2026-11-15',
        'reason' => 'Tenant relocating',
        'cancel_open_invoices' => true,
        'credit_unearned' => false,
    ]);

    expect($earned->fresh()->status)->not->toBe('cancelled')
        ->and((float) $earned->fresh()->balance)->toBe(70000.0);
});

it('cancels an invoice for a period that never happened', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
    ]);

    // December — entirely after the termination. Nothing was earned, so the whole document goes.
    $future = makeInvoice($lease, [
        'status' => 'issued', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
        'total' => 36000, 'balance' => 36000, 'paid_amount' => 0,
    ]);

    $this->svc->terminate($lease, [
        'termination_date' => '2026-11-15',
        'reason' => 'Tenant relocating',
        'cancel_open_invoices' => true,
        'credit_unearned' => false,
    ]);

    expect($future->fresh()->status)->toBe('cancelled')
        ->and((float) $future->fresh()->balance)->toBe(0.0);
});

it('leaves a STRADDLING invoice alone, so the unearned credit has something to credit', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'billing_frequency' => 'quarterly',
    ]);

    // The exact shape that started this: a quarter billed in advance, terminated mid-way.
    $quarter = makeInvoice($lease, [
        'status' => 'issued', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31',
        'total' => 253260, 'balance' => 253260, 'paid_amount' => 0,
    ]);

    $this->svc->terminate($lease, [
        'termination_date' => '2026-11-15',
        'reason' => 'Tenant relocating',
        'cancel_open_invoices' => true,
        'credit_unearned' => false,
    ]);

    // Cancelling it would have wiped the 126,630 earned across October and half of November.
    expect($quarter->fresh()->status)->not->toBe('cancelled')
        ->and((float) $quarter->fresh()->balance)->toBe(253260.0);
});

it('still refuses to cancel a partially-paid invoice, whatever its period', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
    ]);

    $partPaid = makeInvoice($lease, [
        'status' => 'partially_paid', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31',
        'total' => 36000, 'balance' => 16000, 'paid_amount' => 20000,
    ]);

    $this->svc->terminate($lease, [
        'termination_date' => '2026-11-15',
        'reason' => 'Tenant relocating',
        'cancel_open_invoices' => true,
        'credit_unearned' => false,
    ]);

    // Cancelling would orphan the tenant's payment against a record claiming no balance.
    expect($partPaid->fresh()->status)->toBe('partially_paid');
});
