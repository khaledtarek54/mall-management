<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Regression: quarterly charge timing — App\Services\MonthlyBillingService
|--------------------------------------------------------------------------
| BUG (fixed): chargeAppliesToPeriod() used diffInMonths() to decide whether
| a quarterly charge falls due. diffInMonths() counts WHOLE months, so a
| mid-month start (e.g. the 15th) under-counts when the period's day-of-month
| is earlier than the start's — pushing the quarter a month late. A charge
| starting 2026-01-15 would (wrongly) bill in 2026-05 instead of 2026-04.
|
| FIX: use a day-of-month-agnostic calendar-month delta:
|   ((periodYear - startYear) * 12 + periodMonth - startMonth) % 3 === 0
|
| These tests pin the corrected cadence: the quarterly charge applies in
| 2026-04 (exactly 3 calendar months after the Jan start) and is skipped in
| 2026-05.
*/

/** A quarterly charge anchored on a mid-month start date (mirrors BillingScenarioTest). */
function quarterlyCharge(Lease $lease, array $attrs = []): Charge
{
    return Charge::create(array_merge([
        'lease_id' => $lease->id,
        'name' => 'Quarterly Levy',
        'type' => 'other',
        'amount' => 9000,
        'currency' => 'EGP',
        'frequency' => 'quarterly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-15',
        'is_active' => true,
    ], $attrs));
}

/** A lease that comfortably spans the whole 2026 billing window. */
function quarterlyLease(array $attrs = []): Lease
{
    $asset = makeAsset(['code' => 'QTR' . strtoupper(substr(uniqid(), -3))]);
    $unit = makeUnit($asset, ['status' => 'occupied']);

    return makeLease($unit, null, array_merge([
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'payment_terms_days' => 7,
    ], $attrs));
}

beforeEach(function () {
    Notification::fake();
});

it('bills a mid-month quarterly charge exactly 3 calendar months after its start (April)', function () {
    $lease = quarterlyLease();
    quarterlyCharge($lease, ['start_date' => '2026-01-15', 'amount' => 9000]);

    // 2026-01 → 2026-04 is a 3 calendar-month delta: the quarter is due.
    // Pre-fix diffInMonths() saw only 2 whole months (Jan 15 → Apr 1) and skipped it.
    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    expect($result['status'])->toBe('created')
        ->and($result['invoice'])->toBeInstanceOf(Invoice::class)
        ->and($result['invoice']->items()->count())->toBe(1)
        ->and((float) $result['invoice']->subtotal)->toBe(9000.00)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('does NOT bill the mid-month quarterly charge in the off-quarter month (May)', function () {
    $lease = quarterlyLease();
    quarterlyCharge($lease, ['start_date' => '2026-01-15', 'amount' => 9000]);

    // 2026-01 → 2026-05 is a 4 calendar-month delta (4 % 3 = 1): not a quarter.
    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-05-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_applicable_charges')
        ->and($result['invoice'])->toBeNull()
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});
