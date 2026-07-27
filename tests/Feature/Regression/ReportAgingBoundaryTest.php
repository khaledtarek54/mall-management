<?php

use App\Models\Payment;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Reports module (17) — aging boundary + monthly-close billed base
|--------------------------------------------------------------------------
| Two correctness bugs the isolation tests couldn't see (the old aging test
| used a MIDNIGHT asOf, which hides the boundary bug entirely):
|   1. arAgingBuckets() read the raw Carbon float diffInDays(asOf) — since
|      due_date is midnight but a real asOf carries a time (monthlyClose passes
|      endOfMonth 23:59:59), a whole-day-overdue invoice read as N.99 and
|      over-aged by a full bucket; and it didn't reconcile with the (int)-based
|      drilldown the Reports page links each bucket total to.
|   2. monthlyClose() folded cancelled + draft invoices into the billed total /
|      count / VAT and the collections denominator (revenueByType excluded them,
|      so the report contradicted itself).
*/

beforeEach(function () {
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
});

it('ages a whole-day-overdue invoice into the right bucket and reconciles summary with drilldown', function () {
    // A realistic asOf that carries a TIME (as monthlyClose does via endOfMonth()).
    $asOf = CarbonImmutable::parse('2026-06-30')->endOfDay();

    // Due EXACTLY 30 days before asOf's day → belongs in 1–30, NOT 31–60.
    makeInvoice($this->lease, ['issue_date' => '2026-05-01', 'due_date' => '2026-05-31', 'balance' => 1000, 'status' => 'issued']);
    // Due ON asOf's day → not overdue yet → current, NOT 1–30.
    makeInvoice($this->lease, ['issue_date' => '2026-06-01', 'due_date' => '2026-06-30', 'balance' => 500, 'status' => 'issued']);

    $svc = app(ReportService::class);
    $buckets = $svc->arAgingBuckets($asOf);

    expect($buckets['d_1_30']['total'])->toBe(1000.0)     // was wrongly d_31_60 before the fix
        ->and($buckets['d_31_60']['total'])->toBe(0.0)
        ->and($buckets['current']['total'])->toBe(500.0);  // was wrongly d_1_30 before the fix

    // The Reports page links each summary bucket total to this drilldown — they must reconcile.
    foreach (['current', 'd_1_30', 'd_31_60', 'd_61_90', 'd_90_plus'] as $b) {
        expect(round((float) $svc->arAgingDrilldown($b, $asOf)->sum('balance'), 2))
            ->toBe($buckets[$b]['total']);
    }
});

it('excludes cancelled and draft invoices from the monthly-close billed total + collections rate', function () {
    makeInvoice($this->lease, ['issue_date' => '2026-03-10', 'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000, 'status' => 'issued']);
    makeInvoice($this->lease, ['issue_date' => '2026-03-11', 'subtotal' => 9999, 'vat_amount' => 0, 'total' => 9999, 'balance' => 0, 'status' => 'cancelled']);
    makeInvoice($this->lease, ['issue_date' => '2026-03-12', 'subtotal' => 8888, 'vat_amount' => 0, 'total' => 8888, 'balance' => 8888, 'status' => 'draft']);

    Payment::create([
        'reference' => 'P-RPT', 'tenant_id' => $this->lease->tenant_id,
        'amount' => 5000, 'currency' => 'EGP', 'method' => 'card',
        'status' => 'captured', 'payment_date' => '2026-03-15',
    ]);

    $report = app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-03-01'));

    // Billed headline = only the issued invoice — never the cancelled/draft.
    expect($report['invoices']['count'])->toBe(1)
        ->and((float) $report['invoices']['total'])->toBe(10000.0)
        // 5000 collected / 10000 billable = 50% (denominator excludes cancelled + draft).
        ->and($report['collections_rate'])->toBe(50.0);

    // ...but the by_status breakdown still lists every status.
    expect($report['invoices']['by_status'])->toHaveKeys(['issued', 'cancelled', 'draft']);
});
