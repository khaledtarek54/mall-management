<?php

use App\Models\Area;
use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Services\SyncCamPoolFromLedgerService;

/**
 * A pool's estimate must be ITS charge codes, billed to ITS participants.
 *
 * `billedServiceChargeQuery()` knew neither. It matched one global constant
 * (`ESTIMATE_ITEM_TYPES = ['service_charge']`) against every invoice on the property, so:
 *
 *  - **Two pools consumed the same estimate.** A mall running a `cam` pool and a `tax` pool for
 *    2026 had the tax pool subtract the tenant's entire year of billed service charge: allocated
 *    20,000 − estimate 100,000 = **−80,000**, an issued credit note auto-applied FIFO against live
 *    AR. `BASIS_BILLED` was the form default, so the risky basis was the one you got.
 *  - **An area-scoped pool subtracted the whole property.** The food-court pool's collections
 *    included every shop in the mall.
 *
 * `SeveralRecoveryPoolsTest` pinned every fixture to `BASIS_STATED`, so nothing saw either.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);
    $this->lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'status' => 'active',
    ]);

    // A year of billed service charge — the estimate the CAM pool is entitled to subtract.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => '2026-06-01',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => 100000, 'vat_rate' => 14, 'vat_amount' => 14000, 'total' => 114000,
    ]);
});

function makeEstimateScopePool(array $attributes = []): CamExpensePool
{
    return CamExpensePool::create(array_merge([
        'asset_id' => test()->asset->id,
        'period_year' => 2026,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'estimate_basis' => CamExpensePool::BASIS_BILLED,
        'total_actual_expense' => 0,
        'total_estimated_collected' => 0,
    ], $attributes));
}

it('gives the CAM pool the service charge it was always entitled to', function () {
    // The control. A pool that has not declared its codes falls back to the constant — every row
    // written before the column did is exactly this, and none of them may change behaviour.
    $pool = makeEstimateScopePool();

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))->toBe(100000.0);
});

it('refuses to reconcile a tax pool that has not said what it bills', function () {
    // The −80,000 scenario. Before this, the tax pool silently took CAM's service charge.
    $pool = makeEstimateScopePool(['pool_code' => 'tax']);

    expect(fn () => app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))
        ->toThrow(DomainException::class);
});

it('gives a tax pool only the codes it declared', function () {
    $pool = makeEstimateScopePool([
        'pool_code' => 'tax',
        'estimate_charge_codes' => ['property_tax'],
    ]);

    // No property_tax was ever billed, so the pool collected nothing — which is the truth, and is
    // what stops it issuing a credit note for CAM's money.
    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))->toBe(0.0);
});

it('counts a declared code that WAS billed', function () {
    // Paired with the case above: a refusal or a zero passes just as happily when the query is
    // broken in the other direction and matches nothing at all.
    $pool = makeEstimateScopePool([
        'pool_code' => 'tax',
        'estimate_charge_codes' => ['service_charge'],
    ]);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))->toBe(100000.0);
});

it('excludes a lease outside an area-scoped pool', function () {
    $foodCourt = Area::create(['asset_id' => $this->asset->id, 'name' => 'Food court', 'code' => 'FC']);

    // The billed lease sits OUTSIDE the food court, so the food-court pool collected nothing.
    $pool = makeEstimateScopePool(['participant_scope' => CamExpensePool::PARTICIPANTS_AREA, 'participant_area_id' => $foodCourt->id]);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))->toBe(0.0);
});

it('includes a lease inside an area-scoped pool', function () {
    $foodCourt = Area::create(['asset_id' => $this->asset->id, 'name' => 'Food court', 'code' => 'FC']);
    $this->unit->update(['area_id' => $foodCourt->id]);

    $pool = makeEstimateScopePool(['participant_scope' => CamExpensePool::PARTICIPANTS_AREA, 'participant_area_id' => $foodCourt->id]);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($pool))->toBe(100000.0);
});

it('counts a departed tenant who WAS billed during the reconciled year', function () {
    // Participation is deliberately not status-filtered. An allocation target must be a live lease,
    // but a tenant who left in July still paid six months of estimate, and dropping it would
    // understate collections and over-recover from whoever is still trading.
    $this->lease->update(['status' => 'terminated']);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices(makeEstimateScopePool()))->toBe(100000.0);
});

it('excludes another property entirely', function () {
    // The asset clamp used to come from a join this change replaced with the participant subquery;
    // isolation must survive that swap.
    $other = makeAsset(['code' => 'OTHER']);
    $otherLease = makeLease(makeUnit($other), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'status' => 'active',
    ]);
    $otherInvoice = makeInvoice($otherLease, [
        'status' => 'issued', 'issue_date' => '2026-06-01',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
    ]);
    InvoiceItem::create([
        'invoice_id' => $otherInvoice->id,
        'type' => 'service_charge', 'description' => 'Service charge',
        'amount' => 55000, 'vat_rate' => 14, 'vat_amount' => 7700, 'total' => 62700,
    ]);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices(makeEstimateScopePool()))->toBe(100000.0);
});
