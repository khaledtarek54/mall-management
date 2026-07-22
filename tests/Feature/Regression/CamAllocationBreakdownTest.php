<?php

use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Schemas\CamExpensePoolForm;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;

/**
 * CAM UX — the reconciliation is a working (share → allocated → cap legs → estimate → true-up →
 * recovery VAT → admin fee → net). This pins the service read model (explainAllocation), the branched
 * "billed" notification (recovery / credit / fee-only — the old copy said "true-up added" for all
 * three), the freeze-basis UX guard, and that the breakdown schema builds native entries.
 */
function camBreakdownAlloc(float $actual, float $estimated, float $vatRate = 0, ?float $feePct = null): CamAllocation
{
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant()); // single lease → 100% share
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'recovery_vat_rate' => $vatRate, 'admin_fee_pct' => $feePct, 'status' => 'draft',
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    return $pool->allocations()->sole();
}

it('explainAllocation exposes every leg of a positive true-up', function () {
    $w = app(CamReconciliationService::class)->explainAllocation(camBreakdownAlloc(120000, 100000, 14));

    expect($w['allocated'])->toBe(120000.0)
        ->and($w['estimated_paid'])->toBe(100000.0)
        ->and($w['true_up'])->toBe(20000.0)
        ->and($w['recovery_vat'])->toBe(2800.0)      // 20,000 × 14%
        ->and($w['direction'])->toBe('recover')
        ->and($w['net_invoiced'])->toBe(22800.0)     // 20,000 + 2,800 (no fee)
        ->and($w['cap_applied'])->toBeFalse();
});

it('explainAllocation reports a credit direction + net credited on an over-payment', function () {
    $w = app(CamReconciliationService::class)->explainAllocation(camBreakdownAlloc(80000, 100000, 14));

    expect($w['true_up'])->toBe(-20000.0)
        ->and($w['direction'])->toBe('credit')
        ->and($w['net_invoiced'])->toBe(22800.0);    // |−20,000| × 1.14 credited
});

it('the billed notification body branches on recover vs credit vs fee-only', function () {
    $svc = app(CamReconciliationService::class);

    $recovery = CamAllocationsRelationManager::billedNotificationBody($svc->bill(camBreakdownAlloc(120000, 100000)));
    $credit = CamAllocationsRelationManager::billedNotificationBody($svc->bill(camBreakdownAlloc(80000, 100000)));

    expect($recovery)->toContain('Recovery invoice')->toContain('20,000');
    expect($credit)->toContain('credit')->toContain('20,000');
});

it('basisFrozen flips true once an allocation is billed (freezes the recovery-basis fields)', function () {
    $alloc = camBreakdownAlloc(120000, 100000);
    $pool = $alloc->pool;

    expect(CamExpensePoolForm::basisFrozen($pool))->toBeFalse();

    app(CamReconciliationService::class)->bill($alloc);

    expect(CamExpensePoolForm::basisFrozen($pool->refresh()))->toBeTrue();
});

it('breakdownSchema builds native infolist entries incl. the net line, no cap leg when uncapped', function () {
    $names = collect(CamAllocationsRelationManager::breakdownSchema(camBreakdownAlloc(120000, 100000, 14)))
        ->map->getName();

    expect($names)->toContain('bd_true_up')->toContain('bd_recovery_vat')->toContain('bd_net')
        ->not->toContain('bd_cap_absorbed'); // uncapped → the cap legs are omitted
});
