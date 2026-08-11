<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Voiding a wrong invoice must not block re-billing that lease-month.
 *
 * `alreadyBilledForMonth()` had no status filter, so a `cancelled` invoice still satisfied it —
 * on both the bulk run and the manual action. Void an incorrect August invoice intending to
 * regenerate it and both paths reported `skipped: already_billed` **forever**, indistinguishable
 * in the run summary from a lease that had been billed correctly. Silent lost revenue whose only
 * symptom is money that never arrives.
 *
 * The same method also excludes the one-off invoice types that are dated INTO a month the
 * recurring run bills, so they don't suppress that month's rent. `nsf_fee` was the fourth instance
 * of that class and the only one still missing: a bounced-cheque fee is dated to the current month
 * (`BillBouncedChequeFeeService`), so a tenant whose cheque bounced silently lost their rent
 * invoice for that month.
 */
beforeEach(function () {
    $this->period = CarbonImmutable::create(2026, 8, 1);

    $asset = makeAsset(['code' => 'MALL']);
    $unit = makeUnit($asset, ['status' => 'vacant']);
    $this->lease = makeLease($unit, makeTenant(), [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'base_rent_monthly' => 10000,
    ]);

    \App\Models\Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base rent',
        'type' => 'base_rent',
        'amount' => 10000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);
});

it('re-bills the month after the wrong invoice is cancelled', function () {
    $svc = app(MonthlyBillingService::class);

    $first = $svc->generateForLease($this->lease, $this->period);
    expect($first['status'])->toBe('created');

    // The operator spots an error and voids it — the documented correction.
    $first['invoice']->update(['status' => 'cancelled']);

    $second = $svc->generateForLease($this->lease->fresh(), $this->period);

    // Before this, the cancelled invoice still satisfied alreadyBilledForMonth() and the lease
    // could never be billed for August again.
    expect($second['status'])->toBe('created')
        ->and($second['invoice']->id)->not->toBe($first['invoice']->id);
});

it('still refuses to bill the same month twice while the invoice stands', function () {
    // The paired control: if the status filter were too broad, this would double-bill.
    $svc = app(MonthlyBillingService::class);

    $svc->generateForLease($this->lease, $this->period);
    $second = $svc->generateForLease($this->lease->fresh(), $this->period);

    expect($second['status'])->toBe('skipped');
});

it('does not re-bill a month whose invoice was written off', function () {
    $svc = app(MonthlyBillingService::class);
    $first = $svc->generateForLease($this->lease, $this->period);

    // A written-off debt was RIGHTLY billed and is still on the books as bad debt — re-billing it
    // would charge the tenant twice. Deliberately not in the exclusion, unlike `cancelled`.
    $first['invoice']->update(['status' => 'written_off']);

    expect($svc->generateForLease($this->lease->fresh(), $this->period)['status'])->toBe('skipped');
});

it('bills the rent even when an NSF fee invoice sits in the same month', function () {
    // A bounced-cheque fee is its own invoice, dated to the month the cheque bounced — which
    // overlaps the recurring run's window. Without the exclusion it reads as "already billed" and
    // the tenant silently loses that month's rent.
    $nsf = Invoice::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-08-14',
        'due_date' => '2026-08-21',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'subtotal' => 250, 'vat_amount' => 0, 'total' => 250,
        'paid_amount' => 0, 'balance' => 250,
    ]);
    InvoiceItem::create([
        'invoice_id' => $nsf->id,
        'type' => 'nsf_fee',
        'description' => 'Returned cheque fee',
        'amount' => 250, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 250,
    ]);

    $result = app(MonthlyBillingService::class)->generateForLease($this->lease, $this->period);

    expect($result['status'])->toBe('created')
        ->and((float) $result['invoice']->total)->toBe(10000.0);
});
