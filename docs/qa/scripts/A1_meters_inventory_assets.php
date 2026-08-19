<?php

require __DIR__.'/boot.php';
use App\Models\Asset;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\MeterReading;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\BillMeterReadingService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\StockMovementService;
use App\Services\VoidInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

$asset = Asset::where('code', 'AW')->firstOrFail();
$acct = fn (string $r) => app(AccountResolver::class)->id($r);
$sync = fn ($m) => qa_sync($m);

/* ══════════════════════════ MODULE 10 · UTILITY METERS ══════════════════════════ */
qa_section('METERS 1 — the price is a DATED rung, resolved for the reading date');
$tariff = UtilityTariff::create(['code' => 'QA-EL', 'name_en' => 'QA Electricity',
    'name_ar' => 'كهرباء', 'utility_type' => 'electric', 'unit_of_measurement' => 'kWh',
    'provider' => 'QA Co', 'is_active' => true]);
UtilityTariffRate::create(['utility_tariff_id' => $tariff->id, 'rate_per_unit' => 2.50, 'effective_from' => '2025-01-01']);
UtilityTariffRate::create(['utility_tariff_id' => $tariff->id, 'rate_per_unit' => 3.00, 'effective_from' => '2026-08-01']);
qa_eq('the rate in force in July', 2.50, $tariff->fresh()->rateOn('2026-07-15'));
qa_eq('…and after the 1 August rise', 3.00, $tariff->fresh()->rateOn('2026-08-15'));
qa_ok('a date before every rung has no rate (never a guess)', $tariff->fresh()->rateOn('2024-01-01') === null);

$leasedUnit = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->firstOrFail();
$lease = Lease::where('unit_id', $leasedUnit->id)->where('status', 'active')->firstOrFail();
$meter = UtilityMeter::create(['asset_id' => $asset->id, 'unit_id' => $leasedUnit->id,
    'utility_tariff_id' => $tariff->id,
    'meter_number' => 'QA-EL-'.strtoupper(bin2hex(random_bytes(3))), 'type' => 'electric',
    'unit_of_measurement' => 'kWh', 'status' => 'active', 'installed_at' => '2025-01-01']);
qa_eq('a July reading is priced at the July rate', 2500.00, $meter->costFor(1000, '2026-07-31'));
qa_eq('an August reading is priced at the August rate', 2400.00, $meter->costFor(800, '2026-08-31'));

qa_section('METERS 2 — recharge invoice, VAT and the GL');
$mkReading = function (float $prev, float $now, string $date) use ($meter): MeterReading {
    $consumption = round($now - $prev, 2);

    return MeterReading::create(['utility_meter_id' => $meter->id, 'reading_date' => $date,
        'reading_value' => $now, 'consumption' => $consumption, 'cost' => $meter->costFor($consumption, $date)]);
};
$r1 = $mkReading(0, 1000, '2026-07-31');
$r2 = $mkReading(1000, 1800, '2026-08-31');
qa_eq('August consumption', 800.00, (float) $r2->fresh()->consumption);
qa_eq('…priced at the rate in force THEN, not today', 2400.00, (float) $r2->fresh()->cost);
$inv = app(BillMeterReadingService::class)->bill($r2->fresh());
printf("  recharge invoice %s total=%s\n", $inv->number, number_format((float) $inv->total, 2));
qa_eq('the recharge bills the reading cost', 2400.00, round((float) $inv->subtotal, 2));
qa_eq('…as a taxable supply at 14%', 336.00, round((float) $inv->vat_amount, 2));
qa_eq('…to the unit lease tenant', $lease->tenant_id, (int) $inv->tenant_id);
qa_eq('…dated to the CONSUMPTION period, not today', '2026-08-01', $inv->period_start?->format('Y-m-d'));
$e = $sync($inv->fresh());
qa_dump_entry($e, 'utility recharge');
qa_ok('it credits UTILITY revenue, not rent',
    $e->lines->firstWhere('ledger_account_id', $acct('utility_revenue')) !== null);

qa_section('METERS 3 — a reading is billed once, and only a CANCELLED invoice frees it');
$same = app(BillMeterReadingService::class)->bill($r2->fresh());
qa_eq('re-billing returns the SAME invoice', $inv->id, $same->id);
app(VoidInvoiceService::class)->void($inv->fresh(), 'QA');
$rebilled = app(BillMeterReadingService::class)->bill($r2->fresh());
qa_ok('a CANCELLED recharge frees the reading to re-bill', $rebilled->id !== $inv->id, $rebilled->number);

qa_section('METERS 4 — refusals');
$common = UtilityMeter::create(['asset_id' => $asset->id, 'unit_id' => null, 'utility_tariff_id' => $tariff->id,
    'meter_number' => 'QA-CA-'.strtoupper(bin2hex(random_bytes(3))), 'type' => 'electric',
    'unit_of_measurement' => 'kWh', 'status' => 'active', 'installed_at' => '2025-01-01']);
$cr = MeterReading::create(['utility_meter_id' => $common->id, 'reading_date' => '2026-08-31',
    'reading_value' => 500, 'consumption' => 500, 'cost' => 1500]);
qa_refuses('a landlord/common-area meter has nobody to recharge',
    fn () => app(BillMeterReadingService::class)->bill($cr->fresh()));
$vacantUnit = Unit::where('asset_id', $asset->id)->where('status', 'vacant')->firstOrFail();
$vm = UtilityMeter::create(['asset_id' => $asset->id, 'unit_id' => $vacantUnit->id, 'utility_tariff_id' => $tariff->id,
    'meter_number' => 'QA-VC-'.strtoupper(bin2hex(random_bytes(3))), 'type' => 'water',
    'unit_of_measurement' => 'm3', 'status' => 'active', 'installed_at' => '2025-01-01']);
$vr = MeterReading::create(['utility_meter_id' => $vm->id, 'reading_date' => '2026-08-31',
    'reading_value' => 50, 'consumption' => 50, 'cost' => 500]);
qa_refuses('a vacant unit has no lease to bill', fn () => app(BillMeterReadingService::class)->bill($vr->fresh()));
// Dated INSIDE the lease term, so the refusal is the zero-cost one and not "no lease".
$zr = MeterReading::create(['utility_meter_id' => $meter->id, 'reading_date' => '2026-08-30',
    'reading_value' => 1800, 'consumption' => 0, 'cost' => 0]);
qa_refuses('a zero-cost reading bills nothing — the safe direction for an import with no tariff',
    fn () => app(BillMeterReadingService::class)->bill($zr->fresh()), 'no cost to recharge');

/* ══════════════════════════ MODULE 22 · INVENTORY ══════════════════════════ */
qa_section('INVENTORY 1 — receipt, weighted-average cost, and the GL');
$wh = Warehouse::where('asset_id', $asset->id)->firstOrFail();
$item = InventoryItem::create(['name' => 'QA Filter', 'sku' => 'QA-'.strtoupper(bin2hex(random_bytes(3))),
    'unit_of_measurement' => 'pcs', 'reorder_level' => 5, 'is_active' => true]);
$svc = app(StockMovementService::class);
$invBefore = qa_role_balance('inventory');
$svc->receive($wh, $item, 100, 10);
$svc->receive($wh, $item, 100, 20);
qa_eq('on hand after two receipts', 200.0, $svc->onHand($item, $wh));
qa_eq('weighted-average cost = (100×10 + 100×20) / 200', 15.0, $svc->weightedAverageCost($item, $wh));
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_eq('inventory rose by the goods value', 3000.00, round(qa_role_balance('inventory') - $invBefore, 2));

qa_section('INVENTORY 2 — an issue cannot take more than is on hand');
qa_refuses('issuing more than on hand is refused',
    fn () => $svc->adjust($wh, $item, -500), null, Throwable::class);
qa_eq('…and nothing moved', 200.0, $svc->onHand($item, $wh));
$svc->adjust($wh, $item, -50);
qa_eq('a valid issue reduces stock', 150.0, $svc->onHand($item, $wh));

qa_section('INVENTORY 3 — a transfer moves stock between warehouses, never creating any');
$wh2 = Warehouse::where('asset_id', $asset->id)->where('id', '!=', $wh->id)->first()
    ?? Warehouse::create(['asset_id' => $asset->id, 'name' => 'QA Store 2', 'code' => 'QA2', 'is_active' => true]);
$before = $svc->onHand($item);
$svc->transfer($wh, $wh2, $item, 50);
qa_eq('source falls', 100.0, $svc->onHand($item, $wh));
qa_eq('destination rises', 50.0, $svc->onHand($item, $wh2));
qa_eq('total on hand is unchanged', $before, $svc->onHand($item));
qa_refuses('a transfer to the same warehouse is refused',
    fn () => $svc->transfer($wh, $wh, $item, 10), null, Throwable::class);

/* ══════════════════════════ MODULE 23 · FIXED ASSETS ══════════════════════════ */
qa_section('FIXED ASSETS 1 — straight-line depreciation');
$fa = FixedAsset::create(['asset_id' => $asset->id, 'name' => 'QA Chiller', 'tag' => 'QA-FA-1',
    'category' => 'HVAC', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 120000,
    'salvage_value' => 0, 'useful_life_months' => 60, 'method' => 'straight_line', 'status' => 'active']);
$dep = app(DepreciationService::class);
qa_eq('depreciable base = cost − salvage', 120000.00, $dep->depreciableBase($fa));
qa_eq('monthly charge = base / life', 2000.00, $dep->monthlyAmount($fa));
qa_eq('nothing accumulated yet', 0.00, $dep->accumulatedFor($fa));
qa_eq('NBV = cost', 120000.00, $dep->netBookValue($fa));
$posted = $dep->run(CarbonImmutable::parse('2026-08-01'), [$asset->id]);
printf("  depreciation entries posted for August: %d\n", $posted);
$fa->refresh();
qa_eq('one month accumulated', 2000.00, $dep->accumulatedFor($fa));
qa_eq('NBV falls by the charge', 118000.00, $dep->netBookValue($fa));
$again = $dep->run(CarbonImmutable::parse('2026-08-01'), [$asset->id]);
qa_eq('re-running the same month posts nothing (idempotent)', 0, $again);
$de = DepreciationEntry::where('fixed_asset_id', $fa->id)->latest('id')->first();
$e2 = $sync($de);
qa_dump_entry($e2, 'depreciation');
qa_ok('Dr depreciation expense', (float) ($e2->lines->firstWhere('ledger_account_id', $acct('depreciation_expense'))?->debit ?? 0) > 0);
qa_ok('Cr accumulated depreciation', (float) ($e2->lines->firstWhere('ledger_account_id', $acct('accumulated_depreciation'))?->credit ?? 0) > 0);

qa_section('FIXED ASSETS 2 — disposal at, above and below NBV');
$fn = 0;
$mkAsset = function (string $n, float $cost) use ($asset, &$fn): FixedAsset {
    return FixedAsset::create(['asset_id' => $asset->id, 'name' => $n, 'tag' => 'QA-FA-D'.(++$fn),
        'category' => 'HVAC', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => $cost,
        'salvage_value' => 0, 'useful_life_months' => 60, 'method' => 'straight_line', 'status' => 'active']);
};
$gainAsset = $mkAsset('QA Gain', 60000);
$d1 = app(DisposeFixedAssetService::class)->dispose($gainAsset->fresh(),
    ['disposed_on' => '2026-08-31', 'proceeds' => 80000, 'method' => 'sale', 'reason' => 'QA sale above NBV']);
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$ge = JournalEntry::where('source_type', $d1->getMorphClass())->where('source_id', $d1->id)->where('status', 'posted')->first();
qa_dump_entry($ge, 'disposal above NBV');
qa_ok('a sale above NBV books a GAIN',
    $ge?->lines->firstWhere('ledger_account_id', $acct('gain_on_disposal')) !== null);
$lossAsset = $mkAsset('QA Loss', 60000);
$d2 = app(DisposeFixedAssetService::class)->dispose($lossAsset->fresh(),
    ['disposed_on' => '2026-08-31', 'proceeds' => 10000, 'method' => 'sale', 'reason' => 'QA sale below NBV']);
Artisan::call('accounting:sync-ledger', ['--all' => true]);
$le = JournalEntry::where('source_type', $d2->getMorphClass())->where('source_id', $d2->id)->where('status', 'posted')->first();
qa_dump_entry($le, 'disposal below NBV');
qa_ok('a sale below NBV books a LOSS',
    $le?->lines->firstWhere('ledger_account_id', $acct('loss_on_disposal')) !== null);
qa_refuses('an already-disposed asset cannot be disposed again',
    fn () => app(DisposeFixedAssetService::class)->dispose($lossAsset->fresh(),
        ['disposed_on' => '2026-09-30', 'proceeds' => 1, 'method' => 'scrap', 'reason' => 'QA']), null, Throwable::class);
// Measured on the asset itself, not on the run's return count — the sweep may legitimately post
// for OTHER assets in the same month, so a count says nothing about this one.
$accBefore = $dep->accumulatedFor($lossAsset->fresh());
$dep->run(CarbonImmutable::parse('2026-09-01'), [$asset->id]);
qa_eq('a disposed asset stops depreciating', $accBefore, $dep->accumulatedFor($lossAsset->fresh()));
qa_ok('…while an in-service asset keeps going',
    $dep->accumulatedFor($fa->fresh()) > 2000.00, 'accumulated '.number_format($dep->accumulatedFor($fa->fresh()), 2));

qa_section('BATCH A TIE-OUT');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after meters, inventory and fixed assets');
$rec = app(BooksReconciliationService::class);
$tie = $rec->glTieOut();
qa_eq('AR ties', 0.0, $tie['ar']['delta']);
qa_eq('AP ties', 0.0, $tie['ap']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
