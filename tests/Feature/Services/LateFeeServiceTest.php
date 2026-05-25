<?php

use App\Services\LateFeeService;
use Carbon\CarbonImmutable;

it('applies late fees to invoices past due_date + grace window', function () {
    config(['billing.late_fee_percent' => 5, 'billing.late_fee_grace_days' => 7]);

    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $stale = makeInvoice($lease, [
        'due_date' => '2026-01-01',
        'status' => 'overdue',
        'balance' => 11400,
    ]);

    makeInvoice($lease, [
        'due_date' => CarbonImmutable::now()->addDays(5),
        'status' => 'issued',
        'balance' => 5000,
    ]);

    $stats = app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-02-15'));

    expect($stats['applied'])->toBe(1);
    expect($stale->fresh()->items()->where('type', 'late_fee')->count())->toBe(1);
});

it('is idempotent: a second pass does not double-apply', function () {
    config(['billing.late_fee_percent' => 5, 'billing.late_fee_grace_days' => 7]);

    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $invoice = makeInvoice($lease, [
        'due_date' => '2026-01-01',
        'status' => 'overdue',
        'balance' => 10000,
    ]);

    $today = CarbonImmutable::parse('2026-02-15');
    app(LateFeeService::class)->runForToday($today);
    $second = app(LateFeeService::class)->runForToday($today);

    expect($second['applied'])->toBe(0);
    expect($second['skipped'])->toBe(1);
});

it('does not touch invoices with a zero balance', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $paid = makeInvoice($lease, [
        'due_date' => '2026-01-01',
        'status' => 'paid',
        'balance' => 0,
        'paid_amount' => 10000,
    ]);

    $stats = app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-02-15'));

    expect($stats['applied'])->toBe(0);
    expect($paid->fresh()->items()->where('type', 'late_fee')->count())->toBe(0);
});
