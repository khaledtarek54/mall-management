<?php

/*
|--------------------------------------------------------------------------
| An invoiced period reads from its invoice, in every column (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel. The billing forecast's reasoning was already right and was applied to ONE
| figure: `total` came from the invoice while the lines, the net and the VAT beside it stayed the
| plan. So a period whose charge was corrected AFTER it was billed rendered a row built from two
| different truths — a service charge reading 14,000 against an invoice total of 58,740 that had
| been raised at 11,000.
|
| Nothing was wrong with either number. What was wrong is that they sat in one row and could not be
| reconciled by anyone reading it, which is the same defect as the control totals that would not add
| up to the ledger.
|
| The plan is a prediction; once the document exists there is nothing left to predict. Reading the
| whole row from the invoice also makes the difference VISIBLE, which is the useful part: the
| operator sees what was actually billed and can decide whether the shortfall needs collecting.
*/

use App\Models\Invoice;
use App\Services\ChargeScheduleService;
use App\Services\LeaseBillingForecastService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
        'base_rent_monthly' => 44000,
    ]);

    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 11000, CarbonImmutable::parse('2026-09-01'),
    );
});

/** The forecast row for a given month. */
function forecastRow($lease, string $month): ?array
{
    return collect(app(LeaseBillingForecastService::class)->forecast($lease->fresh())['rows'])
        ->first(fn (array $r) => CarbonImmutable::instance($r['period_start'])->format('Y-m') === $month);
}

it('shows what was billed, not what the schedule now says', function () {
    // September is invoiced at 11,000 — the figure the tenant holds.
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'subtotal' => 57200, 'vat_amount' => 1540, 'total' => 58740,
        'paid_amount' => 0, 'balance' => 58740,
    ]);
    // All three lines: `Invoice::recomputeTotals()` DERIVES the header from the items, so a
    // one-line fixture is an invoice whose subtotal is that line — the app was right and my first
    // fixture was a document it would never produce.
    foreach ([
        ['base_rent', 'Base Rent - September 2026', 44000, 0, 0],
        ['marketing', 'Marketing Levy - September 2026', 2200, 0, 0],
        ['service_charge', 'Service Charge - September 2026', 11000, 14, 1540],
    ] as [$type, $description, $amount, $rate, $vat]) {
        $invoice->items()->create([
            'type' => $type, 'description' => $description, 'amount' => $amount,
            'vat_rate' => $rate, 'vat_amount' => $vat, 'total' => $amount + $vat,
        ]);
    }

    $invoice->refresh();

    // The correction, made AFTER the invoice went out.
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 14000, CarbonImmutable::parse('2026-09-01'),
    );

    $row = forecastRow($this->lease, '2026-09');

    // Every column from the document — the whole row reconciles.
    expect($row['invoice_number'])->toBe($invoice->number)
        ->and(round((float) $row['subtotal'], 2))->toBe(57200.0)
        ->and(round((float) $row['vat_amount'], 2))->toBe(1540.0)
        ->and(round((float) $row['total'], 2))->toBe(58740.0);

    $service = collect($row['items'])->firstWhere('type', 'service_charge');
    expect(round((float) $service['amount'], 2))->toBe(11000.0);
});

it('still forecasts a period that has NOT been invoiced', function () {
    // The control. A row that read the invoice even when none exists would show nothing at all,
    // and the forecast is the whole reason the tab exists.
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 14000, CarbonImmutable::parse('2026-09-01'),
    );

    $row = forecastRow($this->lease, '2026-10');

    expect($row['invoice_number'])->toBeNull()
        ->and(collect($row['items'])->firstWhere('type', 'service_charge')['amount'])->toBe(14000.0);
});

it('ignores a CANCELLED invoice, because that period still needs billing', function () {
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'cancelled',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100,
        'paid_amount' => 0, 'balance' => 0,
    ]);

    // A cancelled document is not what the period carries — reading it would show 100 against a
    // month that still owes its full rent.
    $row = forecastRow($this->lease, '2026-09');

    expect($row['invoice_number'])->toBeNull()
        ->and((float) $row['total'])->toBeGreaterThan(100.0)
        ->and(Invoice::whereKey($invoice->id)->value('status'))->toBe('cancelled');
});
