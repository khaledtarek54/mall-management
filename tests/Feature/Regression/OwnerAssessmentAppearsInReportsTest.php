<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * An owner's service charge is money the mall is owed, so it must appear where money is counted.
 *
 * Every figure in this file is derived from a query that used to scope invoices by walking
 * `lease -> unit -> asset`. A unit owner has no lease, so that predicate matched nothing and the
 * assessment was **silently absent** — the report still rendered, the total was just short. Nothing
 * errored, nothing warned, and the number looked plausible.
 *
 * The tenant invoice beside it is the control: without it, a report that returned zero for
 * everything would pass every assertion here.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'RPT']);

    // A retailer, billed the ordinary way. The control.
    $this->lease = makeLease(makeUnit($this->asset));
    $this->tenantInvoice = makeInvoice($this->lease, [
        'issue_date' => '2026-03-05', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);

    // A unit owner, billed the assessment. No lease at all.
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset)->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    Charge::create([
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 4000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $this->ownerInvoice = Invoice::query()->where('unit_ownership_id', $ownership->id)->firstOrFail();

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

/** Run a report inside the property's panel context, the way every screen does. */
function inProperty(callable $fn): mixed
{
    return asTenant(test()->asset, $fn);
}

it('counts an owner assessment in the monthly billing summary', function () {
    $summary = inProperty(fn () => app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-03-01')));

    // 10,000 tenant + 4,000 owner. Before the migration this read 10,000 — short by the whole
    // assessment, with nothing to indicate anything was missing.
    expect(round((float) $summary['invoices']['total'], 2))->toBe(14000.00);
});

it('counts an owner assessment in AR ageing', function () {
    $buckets = inProperty(fn () => app(ReportService::class)->arAgingBuckets(CarbonImmutable::parse('2026-03-31')));

    $outstanding = round(array_sum(array_column($buckets, 'total')), 2);

    expect($outstanding)->toBe(14000.00);
});

it('still scopes to the property — an owner assessment in another mall stays out', function () {
    // The migration must not trade a missing row for a leaked one. A second mall's owner invoice
    // must be as invisible here as it was before.
    $other = makeAsset(['code' => 'OTHER']);
    $otherOwnership = UnitOwnership::create([
        'asset_id' => $other->id,
        'unit_id' => makeUnit($other)->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);
    Charge::create([
        'unit_ownership_id' => $otherOwnership->id,
        'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 999, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'is_active' => true, 'start_date' => '2026-01-01',
    ]);
    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $summary = inProperty(fn () => app(ReportService::class)->monthlyClose(CarbonImmutable::parse('2026-03-01')));

    expect(round((float) $summary['invoices']['total'], 2))->toBe(14000.00);
});
