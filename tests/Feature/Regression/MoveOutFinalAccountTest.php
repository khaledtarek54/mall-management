<?php

use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\MoveOutStatementService;
use App\Services\SettleMoveOutService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * One document settles a departing tenant (phase 4, story MF-03 — scenario S8).
 *
 * Move-out is the moment a tenancy's money is settled, and it was a sequence of unconnected manual
 * acts: refund and forfeit were two separate deposit entries with nothing netting them, nothing
 * itemising them, and nothing checking either against the balance actually held.
 *
 * Two things this must get right beyond the arithmetic:
 *   - it says what is NOT knowable yet (the CAM year that reconciles in March), because a statement
 *     that silently omits a pending true-up reads as final when it is not;
 *   - the settled numbers are FROZEN, not re-derived — a statement re-rendered a year later would
 *     otherwise show today's figures rather than the ones that were signed.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function movingOutLease(float $deposit = 540000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 180000,
        'security_deposit' => $deposit,
        'has_marketing_levy' => false,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 180000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2028-01-01', 'is_active' => true,
    ]);

    DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit->asset_id,
        'type' => 'receipt',
        'amount' => $deposit,
        'transaction_date' => '2028-01-01',
        'status' => 'recorded',
    ]);

    return $lease->fresh();
}

it('states the deposit held, the open AR and the credit owed back in one place', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(540000);

    makeInvoice($lease, ['status' => 'overdue', 'balance' => 120000, 'due_date' => '2028-08-08']);

    $statement = app(MoveOutStatementService::class)->for($lease);

    expect($statement['contractual_deposit'])->toBe(540000.0)
        ->and($statement['deposit_held'])->toBe(540000.0)
        ->and($statement['open_ar'])->toBe(120000.0)
        ->and($statement['tenant_credit'])->toBe(0.0)
        // 540,000 held less 120,000 unpaid.
        ->and($statement['net_to_tenant'])->toBe(420000.0)
        ->and($statement['residual_debt'])->toBe(0.0);
});

it('reports a deposit that was never fully collected', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(540000);

    // Only 300,000 ever arrived. Nothing in the system reconciled that against the contract before.
    DepositTransaction::where('lease_id', $lease->id)->first()->update(['amount' => 300000]);

    $statement = app(MoveOutStatementService::class)->for($lease->fresh());

    expect($statement['deposit_held'])->toBe(300000.0)
        ->and($statement['deposit_shortfall'])->toBe(240000.0);
});

it('shows a tenant who still owes money as a residual debt, not a negative refund', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(100000);

    makeInvoice($lease, ['status' => 'overdue', 'balance' => 250000, 'due_date' => '2028-08-08']);

    $statement = app(MoveOutStatementService::class)->for($lease);

    expect($statement['net_to_tenant'])->toBe(0.0)
        ->and($statement['residual_debt'])->toBe(150000.0);
});

it('says which numbers are not knowable yet', function () {
    // S8: the tenant leaves in September; their share of the year's service charge will not be
    // computed until the following March.
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease();

    CamExpensePool::create([
        'asset_id' => $lease->unit->asset_id,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => 0,
        'total_estimated_collected' => 0,
    ]);

    $statement = app(MoveOutStatementService::class)->for($lease);

    expect($statement['pending_trueups'])->toHaveCount(1)
        ->and($statement['pending_trueups'][0]['kind'])->toBe('cam')
        ->and($statement['pending_trueups'][0]['detail'])->toContain('2028');
});

it('reports nothing pending once the year is reconciled', function () {
    // The control: the warning must disappear when it no longer applies, or operators learn to
    // ignore it.
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease();

    CamExpensePool::create([
        'asset_id' => $lease->unit->asset_id,
        'period_year' => 2028,
        'status' => 'reconciled',
        'total_actual_expense' => 0,
        'total_estimated_collected' => 0,
    ]);

    expect(app(MoveOutStatementService::class)->for($lease)['pending_trueups'])->toBeEmpty();
});

it('settles as one disposition — the kept part forfeited, the rest refunded', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(540000);
    $lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'settlement_date' => '2028-09-20',
        'deductions' => [
            ['description' => 'Reinstatement of the shopfront', 'amount' => 35000],
        ],
        'reason' => 'Move-out settled with the tenant.',
        'document_reference' => 'Settlement 09/2028',
    ]);

    expect((float) $result['forfeit']->amount)->toBe(35000.0)
        ->and((float) $result['refund']->amount)->toBe(505000.0)   // 540,000 − 35,000
        ->and($result['forfeit']->status)->toBe('recorded')
        ->and($result['refund']->status)->toBe('recorded')
        // …and the deposit is now fully disposed of: nothing left held.
        ->and(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(0.0);
});

it('freezes the settled statement on the lease history, where it cannot be edited', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(540000);
    makeInvoice($lease, ['status' => 'overdue', 'balance' => 120000, 'due_date' => '2028-08-08']);
    $lease->update(['status' => 'terminated']);

    app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'settlement_date' => '2028-09-20',
        'deductions' => [['description' => 'Damages', 'amount' => 35000]],
        'document_reference' => 'Settlement 09/2028',
    ]);

    $event = $lease->fresh()->events()->where('type', LeaseEvent::TYPE_TERMINATION)->sole();

    expect($event->payload['settlement'])->toBeTrue()
        ->and($event->payload['deposit_held'])->toEqual(540000.0)
        ->and($event->payload['deducted_total'])->toEqual(35000.0)
        ->and($event->payload['refunded'])->toEqual(505000.0)
        ->and($event->payload['open_ar'])->toEqual(120000.0)
        ->and($event->payload['deductions'][0]['description'])->toBe('Damages')
        ->and($event->document_reference)->toBe('Settlement 09/2028');

    // Frozen: the record refuses to be rewritten, so what was signed stays signed.
    expect(fn () => $event->update(['reason' => 'a better story']))->toThrow(DomainException::class);
});

it('refuses deductions larger than the deposit actually held', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(100000);
    $lease->update(['status' => 'terminated']);

    expect(fn () => app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'deductions' => [['description' => 'Catastrophic damage', 'amount' => 250000]],
    ]))->toThrow(InvalidArgumentException::class);

    // The control: a deduction WITHIN the deposit settles fine, so the refusal above is real.
    $result = app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'deductions' => [['description' => 'Cleaning', 'amount' => 10000]],
    ]);

    expect((float) $result['refund']->amount)->toBe(90000.0);
});

it('refuses to settle a lease that has not ended', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease();

    expect(fn () => app(SettleMoveOutService::class)->settle($lease, []))
        ->toThrow(InvalidArgumentException::class);
});

it('nets the unearned-rent credit from termination into the final account', function () {
    // The join between MF-02 and MF-03: the credit note trailing proration raised is money owed
    // back, and a final account that ignored it would short the tenant by exactly that amount.
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = movingOutLease(540000);

    CreditNote::create([
        'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id,
        'status' => 'issued',
        'issue_date' => '2028-09-18',
        'reason' => 'adjustment',
        'subtotal' => 72000, 'vat_amount' => 0, 'total' => 72000,
        'applied_amount' => 0, 'balance' => 72000,
        'currency' => 'EGP',
    ]);

    $statement = app(MoveOutStatementService::class)->for($lease);

    expect($statement['tenant_credit'])->toBe(72000.0)
        ->and($statement['net_to_tenant'])->toBe(612000.0);   // 540,000 + 72,000
});
