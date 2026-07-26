<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\BillMeterReadingService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Module 10 completion — utility RECHARGE. Readings were recorded but could never be billed (no tariff
 * on the meter, and nothing turned a reading into an invoice line), so the operator re-keyed the cost
 * by hand. These pin the recharge path: tariff → cost → a dedicated `utility` invoice → utility_revenue
 * in the GL, idempotent, and — critically — that a recharge dated to the consumption month does NOT
 * make the monthly run think that month is already billed (which would silently skip BASE RENT).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    $this->svc = app(BillMeterReadingService::class);
});

function utilReading(float $cost = 1000, float $rate = 2.5, bool $withLease = true, ?string $date = '2026-03-31'): MeterReading
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    if ($withLease) {
        makeLease($unit, makeTenant(), ['status' => 'active', 'commencement_date' => '2026-01-01']);
    }
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'meter_number' => 'M-'.uniqid(),
        'type' => 'electric', 'status' => 'active', 'unit_of_measurement' => 'kWh', 'rate_per_unit' => $rate,
    ]);

    return MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => $date,
        'reading_value' => 5000, 'consumption' => 400, 'cost' => $cost,
    ]);
}

it('bills a reading as a utility recharge invoice with VAT, and stamps the reading', function () {
    $reading = utilReading(cost: 1000);

    $invoice = $this->svc->bill($reading);

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->vat_amount)->toBe(140.0)   // 14% — utility recharge is taxable
        ->and((float) $invoice->total)->toBe(1140.0)
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->period_start->toDateString())->toBe('2026-03-01') // the CONSUMPTION month
        ->and($invoice->items()->where('type', 'utility')->exists())->toBeTrue()
        ->and($reading->fresh()->billed_invoice_id)->toBe($invoice->id)
        ->and($reading->fresh()->isBilled())->toBeTrue();
});

it('is idempotent — billing the same reading twice does not double-charge', function () {
    $reading = utilReading();

    $first = $this->svc->bill($reading);
    $second = $this->svc->bill($reading->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::whereHas('items', fn ($q) => $q->where('type', 'utility'))->count())->toBe(1);
});

it('refuses to recharge when the meter has no active lease, and when there is no cost', function () {
    expect(fn () => $this->svc->bill(utilReading(withLease: false)))->toThrow(DomainException::class);
    expect(fn () => $this->svc->bill(utilReading(cost: 0)))->toThrow(DomainException::class);
});

it('posts the recharge to utility_revenue and ties AR out through the real sweep', function () {
    $this->svc->bill(utilReading(cost: 1000));

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $credited = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '41104001'))->sum('credit');

    expect($credited)->toBe(1000.0)                                                  // net, VAT booked separately
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue()
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('a utility recharge does NOT suppress that month\'s base rent in the monthly run', function () {
    // The trap: the recharge invoice is dated to the consumption month, which overlaps the monthly
    // run's already-billed probe. Without excluding `utility` there, base rent would silently vanish.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, makeTenant(), ['status' => 'active', 'commencement_date' => '2026-01-01']);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'meter_number' => 'M-'.uniqid(),
        'type' => 'electric', 'status' => 'active', 'unit_of_measurement' => 'kWh', 'rate_per_unit' => 2.5,
    ]);
    $this->svc->bill(MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => '2026-03-31',
        'reading_value' => 5000, 'consumption' => 400, 'cost' => 1000,
    ]));

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $rent = Invoice::whereHas('items', fn ($q) => $q->where('type', 'base_rent'))->where('lease_id', $lease->id)->get();
    expect($rent)->toHaveCount(1)
        ->and((float) $rent->first()->total)->toBe(10000.0);
});

/*
|--------------------------------------------------------------------------
| Re-bill asymmetry — ONLY a cancelled invoice (GL voided) frees a re-bill.
|--------------------------------------------------------------------------
*/

it('does NOT re-bill a reading whose recharge invoice was CREDITED (credited still posts revenue)', function () {
    $reading = utilReading(cost: 1000);
    $invoice = $this->svc->bill($reading);

    // Operator flips the invoice to 'credited' with no credit note → nothing offsets its revenue.
    // A 'credited' invoice KEEPS its AR + utility_revenue posting (only draft/cancelled are skipped),
    // so re-billing would double-count utility_revenue with the first posting still standing.
    $invoice->update(['status' => 'credited']);

    expect($reading->fresh()->isBilled())->toBeTrue();
    $again = $this->svc->bill($reading->fresh());

    expect($again->id)->toBe($invoice->id)                                                       // same doc
        ->and(Invoice::whereHas('items', fn ($q) => $q->where('type', 'utility'))->count())->toBe(1);
});

it('allows re-billing ONLY after the recharge invoice is CANCELLED (its GL entry is voided)', function () {
    $reading = utilReading(cost: 1000);
    $first = $this->svc->bill($reading);
    $first->update(['status' => 'cancelled']);

    expect($reading->fresh()->isBilled())->toBeFalse();
    $second = $this->svc->bill($reading->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($reading->fresh()->billed_invoice_id)->toBe($second->id);
});

it('refuses to hard-delete a reading once it has been billed (protects the invoice back-link)', function () {
    // MeterReading has no soft-deletes, so a raw delete would erase the evidence for a live invoice.
    $reading = utilReading(cost: 1000);
    $this->svc->bill($reading);

    $reading->fresh()->delete();

    expect(MeterReading::whereKey($reading->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Lease resolution — recharge the tenant whose TERM contains the reading.
|--------------------------------------------------------------------------
*/

it('recharges the tenant whose lease term CONTAINS the consumption date, not the current tenant', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenantA = makeTenant();
    $tenantB = makeTenant();
    // A occupied Jan–Feb (now expired); B took over from March (active).
    makeLease($unit, $tenantA, ['status' => 'expired', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-02-28']);
    makeLease($unit, $tenantB, ['status' => 'active', 'commencement_date' => '2026-03-01', 'expiry_date' => '2026-12-31']);
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'meter_number' => 'M-'.uniqid(),
        'type' => 'electric', 'status' => 'active', 'unit_of_measurement' => 'kWh', 'rate_per_unit' => 2.5,
    ]);
    $janReading = MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => '2026-01-31',
        'reading_value' => 5000, 'consumption' => 200, 'cost' => 500,
    ]);

    // A consumed it in January — bill A, never the successor tenant B.
    expect($this->svc->bill($janReading)->tenant_id)->toBe($tenantA->id);
});

it('bills a departed tenant for their own past consumption (old active-only lookup leaked it)', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenantA = makeTenant();
    makeLease($unit, $tenantA, ['status' => 'expired', 'commencement_date' => '2026-01-01', 'expiry_date' => '2026-02-28']);
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'meter_number' => 'M-'.uniqid(),
        'type' => 'electric', 'status' => 'active', 'unit_of_measurement' => 'kWh', 'rate_per_unit' => 2.5,
    ]);
    $reading = MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => '2026-02-15',
        'reading_value' => 5000, 'consumption' => 200, 'cost' => 500,
    ]);

    expect($this->svc->bill($reading)->tenant_id)->toBe($tenantA->id);
});

it('refuses to recharge a reading whose date falls in a vacant period (no lease covers it)', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    // The only lease starts in March; a January reading precedes every lease term.
    makeLease($unit, makeTenant(), ['status' => 'active', 'commencement_date' => '2026-03-01', 'expiry_date' => '2026-12-31']);
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'unit_id' => $unit->id, 'meter_number' => 'M-'.uniqid(),
        'type' => 'electric', 'status' => 'active', 'unit_of_measurement' => 'kWh', 'rate_per_unit' => 2.5,
    ]);
    $reading = MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => '2026-01-31',
        'reading_value' => 5000, 'consumption' => 200, 'cost' => 500,
    ]);

    expect(fn () => $this->svc->bill($reading))->toThrow(DomainException::class);
});
