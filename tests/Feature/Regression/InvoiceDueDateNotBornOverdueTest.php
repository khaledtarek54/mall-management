<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Bug A (lease→invoice sweep 2026-07-19): a late / back-filled / off-the-1st billing run
 * stamped due_date = period_start + terms, so the invoice was BORN OVERDUE — that night's
 * overdue-scan dunned the owner and LateFeeService penalised a same-day bill. The due date
 * now anchors to max(issue_date, today) + terms so nothing is born overdue. issue_date is
 * left at the period start on purpose (it is the GL entry_date + the invoice-number month).
 */
function overdueBugLease(int $termsDays = 7): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'payment_terms_days' => $termsDays,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('does not create a bill that is already overdue when generated late', function () {
    // A back-filled / late run: today is well after the billed period's 1st.
    $this->travelTo(CarbonImmutable::parse('2026-03-20'));
    $lease = overdueBugLease(termsDays: 7);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];

    // issue_date stays at the period start (GL entry_date + invoice-number month)...
    expect($invoice->issue_date->toDateString())->toBe('2026-01-01')
        // ...but the DUE date anchors to today + terms, never the past 1st.
        ->and($invoice->due_date->toDateString())->toBe('2026-03-27')
        ->and($invoice->due_date->startOfDay()->greaterThanOrEqualTo(CarbonImmutable::now()->startOfDay()))->toBeTrue();
});

it('still derives due_date = issue_date + terms for an on-time run', function () {
    // Generated within its own period → issue_date is today, so the anchor is unchanged.
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    $lease = overdueBugLease(termsDays: 7);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];

    expect($invoice->issue_date->toDateString())->toBe('2026-03-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-03-08');
});
