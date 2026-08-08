<?php

use App\Filament\Admin\Pages\BillingRunPreview;
use App\Models\Charge;
use App\Models\Invoice;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * The billing-run preview (UX-05, docs/benchmarks/yardi/08-yardi-ui-ux.md).
 *
 * The one property this feature must have is that **the preview cannot disagree with the run**:
 * both go through `MonthlyBillingService::planInvoiceForLease()`, so a dry run is the run. These
 * tests pin exactly that — a preview computed by a second implementation would be a preview that
 * lies, and an operator who catches it lying once will never trust it again.
 */
function previewLease(\App\Models\Asset $asset, array $leaseAttrs = [], float $rent = 20000, float $service = 5000): \App\Models\Lease
{
    $lease = makeLease(makeUnit($asset), null, array_merge([
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => $service,
    ], $leaseAttrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => $rent, 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => $service, 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'is_active' => true,
    ]);

    return $lease;
}

it('previews exactly what the run then posts, line for line', function () {
    $asset = makeAsset();
    previewLease($asset);
    previewLease($asset, rent: 31000, service: 7500);

    $service = app(MonthlyBillingService::class);
    $june = CarbonImmutable::parse('2026-06-01');

    $preview = $service->previewForPeriod($june, $asset->id);

    expect($preview['totals']['will_bill'])->toBe(2);

    // Post it, then compare the real invoices against what the preview promised.
    $service->runForPeriod($june, $asset->id);

    foreach ($preview['rows'] as $row) {
        $invoice = Invoice::where('lease_id', $row['lease_id'])->sole();

        expect((float) $invoice->subtotal)->toBe($row['subtotal'])
            ->and((float) $invoice->vat_amount)->toBe($row['vat_amount'])
            ->and((float) $invoice->total)->toBe($row['total'])
            ->and($invoice->items()->count())->toBe($row['line_count'])
            ->and($invoice->due_date->toDateString())->toBe($row['due_date']->toDateString());
    }

    expect(round((float) Invoice::sum('total'), 2))->toBe($preview['totals']['total']);
});

it('names the reason a lease is not billing rather than silently omitting it', function () {
    $asset = makeAsset();

    $billing = previewLease($asset);
    $fitOut = previewLease($asset, ['commencement_date' => '2026-06-01', 'fit_out_months' => 3]);
    $quarterly = previewLease($asset, ['commencement_date' => '2026-01-01', 'billing_frequency' => 'quarterly']);
    $noCharges = makeLease(makeUnit($asset), null, ['commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31']);

    $rows = collect(app(MonthlyBillingService::class)
        ->previewForPeriod(CarbonImmutable::parse('2026-06-01'), $asset->id)['rows'])
        ->keyBy('lease_id');

    // Every eligible lease appears — a skipped lease that vanishes from the list is a lease
    // nobody notices went unbilled.
    expect($rows)->toHaveCount(4);

    expect($rows[$billing->id]['billable'])->toBeTrue()
        ->and($rows[$fitOut->id]['reason'])->toBe('fit_out')
        // Quarterly anchored to January bills Jan/Apr/Jul/Oct — June is mid-cycle.
        ->and($rows[$quarterly->id]['reason'])->toBe('off_cycle')
        ->and($rows[$noCharges->id]['reason'])->toBe('no_applicable_charges');
});

it('reports an already-billed lease as already_billed instead of offering it twice', function () {
    $asset = makeAsset();
    $lease = previewLease($asset);
    $service = app(MonthlyBillingService::class);
    $june = CarbonImmutable::parse('2026-06-01');

    $service->runForPeriod($june, $asset->id);
    $rows = collect($service->previewForPeriod($june, $asset->id)['rows'])->keyBy('lease_id');

    expect($rows[$lease->id]['billable'])->toBeFalse()
        ->and($rows[$lease->id]['reason'])->toBe('already_billed')
        ->and($rows[$lease->id]['total'])->toBe(0.0);
});

it('previews the prorated amount a mid-month commencement will actually bill', function () {
    $asset = makeAsset();
    // Commences 15 June in a 30-day month → 16/30.
    previewLease($asset, ['commencement_date' => '2026-06-15'], rent: 30000, service: 0);

    $row = app(MonthlyBillingService::class)
        ->previewForPeriod(CarbonImmutable::parse('2026-06-01'), $asset->id)['rows'][0];

    expect($row['prorated'])->toBeTrue()
        ->and($row['subtotal'])->toBe(16000.0);
});

it('writes nothing — a preview is a dry run', function () {
    $asset = makeAsset();
    previewLease($asset);

    app(MonthlyBillingService::class)->previewForPeriod(CarbonImmutable::parse('2026-06-01'), $asset->id);

    expect(Invoice::count())->toBe(0);
});

it('scopes to one property, so an operator in one mall cannot preview or post another', function () {
    $mallA = makeAsset();
    $mallB = makeAsset();
    previewLease($mallA);
    $leaseB = previewLease($mallB);

    $service = app(MonthlyBillingService::class);
    $june = CarbonImmutable::parse('2026-06-01');

    $preview = $service->previewForPeriod($june, $mallA->id);
    expect($preview['rows'])->toHaveCount(1);

    $service->runForPeriod($june, $mallA->id);

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::where('lease_id', $leaseB->id)->exists())->toBeFalse();
});

it('still runs portfolio-wide when no property is given — the scheduled job is unchanged', function () {
    $mallA = makeAsset();
    $mallB = makeAsset();
    previewLease($mallA);
    previewLease($mallB);

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-06-01'));

    expect(Invoice::count())->toBe(2);
});

it('parses the period safely on the 31st instead of overflowing into the next month', function () {
    // `Y-m` without a day fills the day from TODAY, so parsing "2026-02" on the 31st lands in
    // March. The page uses `!Y-m`; this pins it, because a silently shifted period bills the
    // wrong month.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-31'));

    expect(BillingRunPreview::parsePeriod('2026-02')->format('Y-m'))->toBe('2026-02')
        ->and(BillingRunPreview::parsePeriod('nonsense')->format('Y-m'))->toBe('2026-01')
        ->and(BillingRunPreview::parsePeriod(null)->format('Y-m'))->toBe('2026-01');

    CarbonImmutable::setTestNow();
});
