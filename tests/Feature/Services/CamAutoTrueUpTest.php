<?php

use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Services\CamReconciliationService;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit1 = makeUnit($this->asset, ['code' => 'A1', 'area_sqm' => 100]);
    $this->unit2 = makeUnit($this->asset, ['code' => 'A2', 'area_sqm' => 300]);
    $this->lease1 = makeLease($this->unit1);
    $this->lease2 = makeLease($this->unit2);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 80000,
        'status' => 'draft',
    ]);
});

it('generates allocations and bumps pool to reconciling without auto-bill', function () {
    $report = app(CamReconciliationService::class)->autoTrueUpForYear(2026, autoBill: false);

    expect($report)->toHaveCount(1);
    expect($report[0]['allocations'])->toBe(2);
    expect($report[0]['billed'])->toBe(0);
    expect($report[0]['status'])->toBe('reconciling');

    // No charges should have been created.
    expect($this->lease1->charges()->where('type', 'cam_recovery')->count())->toBe(0);
    expect($this->lease2->charges()->where('type', 'cam_recovery')->count())->toBe(0);
});

it('generates AND bills AND marks reconciled with auto-bill', function () {
    $report = app(CamReconciliationService::class)->autoTrueUpForYear(2026, autoBill: true);

    expect($report[0]['allocations'])->toBe(2);
    expect($report[0]['billed'])->toBe(2);
    expect($report[0]['status'])->toBe('reconciled');

    expect($this->pool->fresh()->reconciled_at)->not->toBeNull();

    // Both leases should now have a CAM Reconciliation charge.
    expect($this->lease1->charges()->where('type', 'cam_recovery')->count())->toBe(1);
    expect($this->lease2->charges()->where('type', 'cam_recovery')->count())->toBe(1);
});

it('bills the positive true-up on invoice items typed cam_recovery (for GL CAM revenue)', function () {
    app(CamReconciliationService::class)->autoTrueUpForYear(2026, autoBill: true);

    // Each under-collected lease's recovery invoice carries a `cam_recovery` item so
    // the GL routes it to CAM Recovery Revenue (إيرادات استرداد المصروفات المشتركة),
    // not the legacy generic 'other' type that fell through to misc income.
    expect(InvoiceItem::where('type', 'cam_recovery')->count())->toBe(2);
    expect(InvoiceItem::where('type', 'other')->count())->toBe(0);
});

it('is idempotent — running twice does not double-bill', function () {
    $svc = app(CamReconciliationService::class);

    $svc->autoTrueUpForYear(2026, autoBill: true);
    $second = $svc->autoTrueUpForYear(2026, autoBill: true);

    // Pool is now 'reconciled', so it's not eligible — empty report on second run.
    expect($second)->toBeEmpty();
    expect($this->lease1->charges()->where('type', 'cam_recovery')->count())->toBe(1);
});

it('skips pools that are already reconciled or closed', function () {
    CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'total_actual_expense' => 50000,
        'total_estimated_collected' => 45000,
        'status' => 'reconciled',
        'reconciled_at' => now()->subMonths(2),
    ]);

    $report = app(CamReconciliationService::class)->autoTrueUpForYear(2025, autoBill: true);

    expect($report)->toBeEmpty();
});

it('returns an empty report when no pools exist for the year', function () {
    $report = app(CamReconciliationService::class)->autoTrueUpForYear(2099, autoBill: true);
    expect($report)->toBeEmpty();
});

it('the cam:reconcile command exits successfully', function () {
    // The command itself is a thin wrapper around autoTrueUpForYear (covered
    // above). Just verify the binding + signature are wired correctly.
    $this->artisan('cam:reconcile', ['--year' => 2099])
        ->assertSuccessful();
});
