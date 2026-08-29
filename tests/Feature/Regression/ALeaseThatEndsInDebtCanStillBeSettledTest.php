<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;

/**
 * "IS THERE ANYTHING TO SETTLE" IS ASKED BEFORE THE ARREARS, NOT AFTER.
 *
 * `SettleMoveOutService` settles the arrears out of the deposit FIRST, then asked whether any
 * deposit was held — against the figure AFTER the arrears had consumed it. So it could not tell
 * "this lease never held a deposit" from "the deposit went where it was supposed to go", and a
 * tenant who leaves owing MORE than their deposit — the ordinary bad exit, and the case the whole
 * feature exists for — was refused with "There is no deposit held on this lease to settle",
 * rolling back the settlement it had just carried out correctly.
 *
 * Measured on demo lease #3: 176,443.55 of arrears against 164,999.91 held. The service applied
 * the deposit across five invoices, left 11,443.64 of residual debt, threw, and rolled all of it
 * back — so that lease could never be closed at all.
 *
 * `$held === 0` after the arrears is a valid OUTCOME, not a refusal. An exit that leaves a debt is
 * exactly the one a landlord most needs a settled, dated record of.
 */
function leaseEndingWith(float $deposit, float $arrears): Lease
{
    $lease = Lease::factory()->create([
        'status' => 'terminated',
        'commencement_date' => CarbonImmutable::parse('2025-01-01'),
        'expiry_date' => CarbonImmutable::parse('2026-06-30'),
        'base_rent_monthly' => 50_000,
        'security_deposit' => $deposit,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 50_000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'start_date' => $lease->commencement_date, 'is_active' => false,
    ]);

    if ($deposit > 0) {
        DepositTransaction::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'asset_id' => $lease->unit->asset_id,
            'type' => 'receipt',
            // 'bank', not 'bank_transfer' — with no payment-method catalogue seeded the column falls
            // back to its FLOOR (cash|bank), which is the shipped set before an operator adds rails.
            'method' => 'bank',
            'amount' => $deposit,
            'transaction_date' => $lease->commencement_date,
            'status' => 'recorded',
        ]);
    }

    if ($arrears > 0) {
        $invoice = Invoice::factory()->create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'asset_id' => $lease->unit->asset_id,
            'status' => 'issued',
        ]);
        $invoice->items()->delete();
        $invoice->items()->create([
            'type' => 'base_rent',
            'description' => 'rent',
            'quantity' => 1,
            'unit_price' => $arrears,
            'amount' => $arrears,
            'vat_rate' => 0,
            'vat_amount' => 0,
        ]);
        $invoice->recomputeTotals();
    }

    return $lease->fresh();
}

it('settles a tenancy whose arrears exceed its deposit', function (): void {
    $lease = leaseEndingWith(deposit: 100_000, arrears: 150_000);

    $result = app(SettleMoveOutService::class)->settle($lease, [
        'settlement_date' => CarbonImmutable::today(),
        'reason' => 'moved out owing rent',
    ]);

    expect(round($result['settled_arrears']['applied'], 2))->toBe(100_000.0)
        ->and(round((float) $result['statement']['net_to_tenant'], 2))->toBe(0.0)
        ->and(round((float) $result['statement']['residual_debt'], 2))->toBe(50_000.0)
        ->and(round($lease->fresh()->depositHeld(), 2))->toBe(0.0);

    // The record of the exit is the point — it must survive, not be rolled back with the refusal.
    expect(LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->exists())->toBeTrue();
});

it('still refunds the balance when the deposit covers the arrears', function (): void {
    $lease = leaseEndingWith(deposit: 100_000, arrears: 30_000);

    $result = app(SettleMoveOutService::class)->settle($lease, [
        'settlement_date' => CarbonImmutable::today(),
        'reason' => 'clean exit',
    ]);

    expect(round($result['settled_arrears']['applied'], 2))->toBe(30_000.0)
        ->and(round((float) $result['statement']['net_to_tenant'], 2))->toBe(70_000.0);
});

it('refuses a lease that never held a deposit and has no deduction', function (): void {
    $lease = leaseEndingWith(deposit: 0, arrears: 20_000);

    expect(fn () => app(SettleMoveOutService::class)->settle($lease, [
        'settlement_date' => CarbonImmutable::today(),
        'reason' => 'nothing to settle',
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a deduction the arrears have already spent, and says what to do instead', function (): void {
    $lease = leaseEndingWith(deposit: 100_000, arrears: 150_000);

    try {
        app(SettleMoveOutService::class)->settle($lease, [
            'settlement_date' => CarbonImmutable::today(),
            'deductions' => [['description' => 'damage', 'amount' => 5_000]],
            'reason' => 'damage on top of arrears',
        ]);
        expect(false)->toBeTrue('the deduction should have been refused');
    } catch (InvalidArgumentException $e) {
        // Not a bare "no". The damage is still owed — it just cannot come out of a deposit that
        // is gone, and the operator has to be told which.
        expect($e->getMessage())->toContain('5,000.00')
            ->and($e->getMessage())->not->toContain('admin.');
    }
});

it('words both refusals in Arabic too', function (string $key): void {
    $ar = trans("admin.move_out.{$key}", ['held' => '0.00', 'deducted' => '0.00'], 'ar');

    expect($ar)->not->toBe("admin.move_out.{$key}")
        ->and($ar)->toMatch('/\p{Arabic}/u');
})->with(['nothing_to_settle', 'deductions_exceed_deposit']);
