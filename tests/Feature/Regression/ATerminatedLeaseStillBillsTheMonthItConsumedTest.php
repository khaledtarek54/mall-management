<?php

/*
|--------------------------------------------------------------------------
| A terminated lease still bills the period it consumed (SW-050)
|--------------------------------------------------------------------------
| The sibling of `EndingAnArrearsChargeStillBillsTheMonthItConsumedTest` — one defect through two
| doors, and this is the door where the money goes OUTBOUND.
|
| A charge billed IN ARREARS is invoiced one cycle behind: September's service charge appears on
| October's invoice, because September's service is not knowable until October. When the lease ends,
| no October invoice is ever raised, so the days the tenant genuinely occupied are billed by NOTHING.
| And `MoveOutStatementService` computes `net = depositHeld + tenantCredit − openAr` from EXISTING
| invoices only — an invoice nobody raised is not open AR — so the refund cheque is larger by exactly
| the unbilled amount, capped by the deposit.
|
| Measured before the fix, on rent 100,000 in advance + service charge 20,000 in arrears, deposit
| 300,000, terminated 20 September with the tenant paid up: the tenant owed **13,333.33** and was
| refunded **300,000.00** where 286,666.67 was right. The tenant has gone; there is no recovery path,
| and `pendingTrueUps()` covers CAM and percentage rent with no unbilled-period term, so nothing on
| the statement says anything is missing.
|
| **The row's stated cause was wrong**, and this test pins why the obvious fix does not work: the
| blanket `is_active = false` is guarded by the same `$underNotice` as the status write, and
| `isBillableForPeriod()` refuses on the STATUS long before any charge row is read.
|
| **Two doors, because a termination dated in the future is NOTICE.** The lease stays active and
| keeps billing, and the period it will consume has not happened yet — so `LeaseTerminationService`
| can only bill an IMMEDIATE termination, and `leases:expire` bills the lease that ends by running
| out. `invoice_items.covered_end` is the idempotency stamp for both; there is no new column.
*/

use App\Models\Charge;
use App\Models\Lease;
use App\Services\LeaseTerminationService;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'TFB']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Rent 100,000 in ADVANCE, service charge 20,000 in ARREARS — the ordinary Egyptian arrangement. */
function leaseBillingPartlyInArrears(float $deposit = 300000, bool $arrears = true): Lease
{
    $lease = makeLease(makeUnit(test()->asset), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'security_deposit' => $deposit,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 100000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => $arrears ? Charge::TIMING_ARREARS : null,
    ]);

    return $lease->fresh();
}

/** Bill every month up to and including `$through`, so the lease has a real invoice history. */
function billMonthlyThrough(Lease $lease, string $through): void
{
    $period = CarbonImmutable::parse('2026-01-01');
    $last = CarbonImmutable::parse($through)->startOfMonth();

    while ($period->lessThanOrEqualTo($last)) {
        CarbonImmutable::setTestNow($period->addDays(1)->setTime(9, 0));
        app(App\Services\MonthlyBillingService::class)->generateForLease($lease->fresh(), $period);
        $period = $period->addMonth();
    }
}

it('raises the consumed arrears period when a lease is terminated immediately', function () {
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20',
        'reason' => 'Tenant relocating',
    ]);

    // 20 of September's 30 days of service charge: 20,000 × 20/30.
    $final = $lease->fresh()->invoices()
        ->whereDate('period_end', '2026-09-20')
        ->latest('id')
        ->first();

    expect($final)->not->toBeNull();

    $types = $final->items->pluck('type');

    expect($types)->toContain('service_charge')
        // The rent was billed in ADVANCE on 1 September and credited for the unearned tail. Raising
        // it again here would be the 86,666.67 double-bill the legacy guard exists to prevent.
        ->and($types)->not->toContain('base_rent')
        ->and(round((float) $final->items->where('type', 'service_charge')->sum('amount'), 2))
        ->toEqual(13333.33);
});

it('shrinks the refund by exactly what was owed — the assertion that names the money', function () {
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    // The tenant is paid up on everything raised so far, and the deposit is held.
    foreach ($lease->fresh()->invoices as $invoice) {
        if ((float) $invoice->balance > 0) {
            $payment = App\Models\Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'payment_date' => '2026-09-01',
                'amount' => (float) $invoice->balance,
                'method' => 'bank_transfer',
                'status' => 'captured',
            ]);
            $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->balance]);
            $invoice->fresh()->recomputeTotals();
        }
    }

    // A deposit is HELD FOR MONEY RECEIVED, never for a figure on the lease — so the receipt has
    // to exist or there is nothing to settle against.
    App\Models\DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit?->asset_id,
        'type' => 'receipt',
        'status' => 'recorded',
        'method' => 'bank',
        'amount' => 300000,
        'transaction_date' => '2026-01-01',
    ]);

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20',
        'reason' => 'Tenant relocating',
    ]);

    $statement = app(SettleMoveOutService::class)->settle($lease->fresh());

    // Refunding 300,000 to a tenant who owes 13,333.33 sends money OUT of the building that will
    // never come back. This is the assertion the whole row is about; the one above names the
    // mechanism, and only this one names the money.
    $refunded = round((float) ($statement['refund']?->amount ?? 0), 2);
    $applied = round((float) ($statement['settled_arrears']['applied'] ?? 0), 2);

    expect($applied)->toEqual(13333.33)
        ->and($refunded)->toEqual(286666.67);
});

it('raises nothing extra for a lease that bills wholly in advance — the untouched case', function () {
    $lease = leaseBillingPartlyInArrears(arrears: false);
    billMonthlyThrough($lease, '2026-09-01');

    $before = $lease->fresh()->invoices()->count();

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20',
        'reason' => 'Tenant relocating',
    ]);

    // An advance row was billed at the START of the period it covers and the unearned tail is
    // credited. A second document here would be a double-bill on the case that already worked.
    expect($lease->fresh()->invoices()->count())->toBe($before);
});

it('bills rent the run never reached either — the guard that would have UNDER-billed', function () {
    // Found by mutation testing, not by reading. Restricting the final bill to ARREARS rows was the
    // obvious guard and it is harmful: the monthly run fires on the 1st, so a lease terminated
    // before it has an unbilled advance month, and the run will never reach that lease again.
    // Measured with the restriction in place: the service charge alone was raised and 66,666.67 of
    // prorated September rent the tenant owed was billed by nothing — SW-050 again, with the
    // landlord on the losing side. `covered_end` is the real mechanism and it is right for both
    // timings; this is also Yardi's rule, that charges prorate to the move-out date, all of them.
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-08-01');   // September's advance rent was never raised

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20', 'reason' => 'Tenant relocating',
    ]);

    $final = $lease->fresh()->invoices()->whereDate('period_end', '2026-09-20')->latest('id')->firstOrFail();

    expect(round((float) $final->items->where('type', 'base_rent')->sum('amount'), 2))
        ->toEqual(66666.67)
        ->and(round((float) $final->items->where('type', 'service_charge')->sum('amount'), 2))
        ->toEqual(33333.33);
});

it('is idempotent — terminating twice raises one final invoice', function () {
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20', 'reason' => 'Tenant relocating',
    ]);

    $after = $lease->fresh()->invoices()->count();

    // The sweep is the second door onto the same act, so it must find nothing left to do. There is
    // no stamp of its own — `invoice_items.covered_end` is the whole mechanism.
    app(App\Services\BillFinalPeriodService::class)
        ->billFor($lease->fresh(), CarbonImmutable::parse('2026-09-20'));

    expect($lease->fresh()->invoices()->count())->toBe($after);
});

it('is NOT defeated by a one-off line — a late fee must not make the lease unbillable', function () {
    // The fatal finding of the adversarial review. `covered_end` is written by the RECURRING run
    // alone; every one-off raiser — late fee, deposit bill, violation fine, utility recharge, CAM
    // recovery, percentage-rent overage, bounced-cheque fee — issues against the LEASE with no
    // `covered_*` at all. An unscoped legacy probe therefore refused any lease that had ever carried
    // ONE of them, with a fully-stamped recurring history beside it, and refused hardest on exactly
    // the leases this exists for: a tenant with late fees is the tenant whose deposit you are
    // netting against. The probe is scoped by `charge_id`, as `lastCoveredEndFor()` already is.
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    $oneOff = makeInvoice($lease->fresh(), [
        'status' => 'issued', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500, 'balance' => 500,
    ]);
    $oneOff->items()->create([
        'type' => 'late_fee', 'description' => 'Late payment fee', 'quantity' => 1,
        'unit_price' => 500, 'amount' => 500, 'tax_amount' => 0, 'total' => 500,
    ]);

    expect($oneOff->fresh()->items->first()->covered_end)->toBeNull();

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20', 'reason' => 'Tenant relocating',
    ]);

    $final = $lease->fresh()->invoices()->whereDate('period_end', '2026-09-20')->latest('id')->first();

    expect($final)->not->toBeNull()
        ->and(round((float) $final->items->where('type', 'service_charge')->sum('amount'), 2))
        ->toEqual(13333.33);
});

it('bills a QUARTERLY lease that ends off-cycle', function () {
    // A quarterly lease is billed only on a cycle start, so passing the calendar month made the
    // planner answer `off_cycle` for two months in every three — no document at all, and the whole
    // consumed cycle billed by nothing. `LeaseTerminationService` already cites "a quarterly lease
    // terminating mid-quarter" as a reproduced real case.
    $lease = leaseBillingPartlyInArrears();
    $lease->update(['billing_frequency' => 'quarterly']);
    $lease = $lease->fresh();

    billMonthlyThrough($lease, '2026-10-01');

    CarbonImmutable::setTestNow('2026-11-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-11-20', 'reason' => 'Tenant relocating',
    ]);

    expect($lease->fresh()->invoices()->whereDate('period_end', '2026-11-20')->exists())->toBeTrue();
});

it('bills a HOLDOVER’s final consumed period — its only door', function () {
    // A converted holdover's expiry is deliberately in the PAST and `holdover_from` is what keeps it
    // billing, so `$isFinalCycle` answers false and an arrears row covers the previous month only.
    // `leases:expire` excludes holdovers, so termination is the one door — the omission would be
    // permanent, and it restores exactly the pre-SW-050 behaviour for that population.
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    $lease->fresh()->update([
        'holdover_from' => '2026-07-01',
        'expiry_date' => '2026-06-30',
    ]);

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => '2026-09-20', 'reason' => 'Holdover ended',
    ]);

    $final = $lease->fresh()->invoices()->whereDate('period_end', '2026-09-20')->latest('id')->first();

    // The same 13,333.33 as the ordinary case: August's arrears was already covered by the
    // September invoice and the clamp trims it, leaving the 20 consumed September days. Without
    // `forceFinalCycle` the arrears window stops at 31 August, the clamp finds it wholly covered,
    // and the service raises NO DOCUMENT — which is the pre-SW-050 behaviour for a holdover.
    expect($final)->not->toBeNull()
        ->and(round((float) $final->items->where('type', 'service_charge')->sum('amount'), 2))
        ->toEqual(13333.33);
});

it('REFUSES a lease whose history predates line-level periods, rather than double-billing it', function () {
    $lease = leaseBillingPartlyInArrears();
    billMonthlyThrough($lease, '2026-09-01');

    // Exactly what every invoice raised before migration 2026_09_03_120000 looks like. SW-051
    // deliberately did not backfill, and null means NOT RECORDED — with no clamp the planner
    // re-raises rent already invoiced AND already credited: measured, an 86,666.67 double-bill,
    // outbound on a document the tenant reads.
    App\Models\InvoiceItem::query()
        ->whereIn('invoice_id', $lease->fresh()->invoices()->pluck('id'))
        ->update(['covered_start' => null, 'covered_end' => null]);

    $before = $lease->fresh()->invoices()->count();

    CarbonImmutable::setTestNow('2026-09-20 10:00:00');

    $result = app(App\Services\BillFinalPeriodService::class)
        ->billFor($lease->fresh(), CarbonImmutable::parse('2026-09-20'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('line_periods_not_recorded')
        ->and($lease->fresh()->invoices()->count())->toBe($before);
});
