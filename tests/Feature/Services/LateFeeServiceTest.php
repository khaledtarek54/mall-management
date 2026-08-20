<?php

use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;

it('applies late fees to invoices past due_date + grace window', function () {
    // The input the service actually reads. A `config([...])` setup used to sit here and was a
    // no-op — nothing has read the billing late-fee config keys since MF-08, and EG-19 deleted the
    // keys themselves, so the setup described a lever that did not exist.
    tap(app(BillingSettings::class), function (BillingSettings $b) {
        $b->late_fee_percent = 5;
        $b->late_fee_grace_days = 7;
    });

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
    expect(lateFeeItems($stale)->count())->toBe(1);
});

it('charges the MINIMUM floor when the percentage is below it (small balance)', function () {
    // fee = max(minimum, balance × pct). With balance 1000 at 2% = 20, the 75 floor is operative.
    //
    // 75, not the shipped default of 50: a setup that merely restates the defaults is satisfied by
    // deleting itself, so it proves nothing about whether the service reads the setting at all.
    tap(app(BillingSettings::class), function (BillingSettings $b) {
        $b->late_fee_percent = 2;
        $b->late_fee_minimum = 75;
        $b->late_fee_grace_days = 7;
    });

    $lease = makeLease(makeUnit(makeAsset()));
    $small = makeInvoice($lease, ['due_date' => '2026-01-01', 'status' => 'overdue', 'balance' => 1000]);

    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2026-02-15'));

    $fee = lateFeeItems($small)->first();
    expect((float) $fee->amount)->toBe(75.0); // the floor, NOT 20 (2% × 1000)
});

it('is idempotent: a second pass does not double-apply', function () {
    tap(app(BillingSettings::class), function (BillingSettings $b) {
        $b->late_fee_percent = 5;
        $b->late_fee_grace_days = 7;
    });

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
    expect(lateFeeItems($paid)->count())->toBe(0);
});
