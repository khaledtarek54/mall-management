<?php

/*
|--------------------------------------------------------------------------
| A document raised against something other than a lease still says whose it is
|--------------------------------------------------------------------------
| Three surfaces, one cause. `invoices.lease_id` and `cam_allocations.lease_id` are nullable by
| design — a unit OWNER is billed through a `UnitOwnership`, not a lease — and each of these
| documents resolved its party, its unit and its property by walking the lease. They did not crash;
| they rendered with the fields blank, which is why nobody reported them.
|
| Pinned here because the commits that fixed the first two peers shipped with NO test: reverting
| either production line left the whole suite green, in a commit titled "the fix was real, the
| evidence for it was not". Each case below asserts a value that only the fix can produce.
*/

use App\Enums\UnitOwnershipStatus;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\UnitOwnership;
use App\Services\CamStatementPdfService;
use App\Services\ReceiptPdfService;
use App\Services\TenantStatementPdfService;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->asset = makeAsset(['name' => 'Atriom Walk']);
});

it('states the owner, the unit and the denominator on a CAM statement', function () {
    // The one document whose entire purpose is showing the working behind a true-up. For an
    // ownership allocation it read: party blank, unit blank, reference blank, "Your area 0.00 m² of
    // 0.00 m²" — three mutually contradictory figures beside a real 2% share and real money. The
    // portal lists these deliberately: `CamAllocationResource::getEloquentQuery()` ORs in
    // `unitOwnership` with a comment naming this exact reader.
    $unit = makeUnit($this->asset, ['area_sqm' => 120]);
    $owner = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Mona Fahmy']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'pool_code' => 'CAM',
        'name' => 'Common area maintenance',
        'total_actual_expense' => 600000,
    ]);

    $allocation = CamAllocation::create([
        'cam_expense_pool_id' => $pool->id,
        'unit_ownership_id' => $ownership->id,
        'lease_id' => null,
        'pro_rata_share_pct' => 2.0,
        'allocated_amount' => 12000,
        'estimated_paid' => 10000,
        'true_up_amount' => 2000,
        'status' => 'pending',
    ]);

    $facts = app(CamStatementPdfService::class)->facts($allocation->fresh());

    // The area is real, from the unit — the same source `CamReconciliationService::areaForPeriod()`
    // apportioned by, so the statement and the calculation agree.
    expect((float) $facts['area_sqm'])->toBe(120.0)
        // 120 m² at a 2% share means the pool was divided by 6,000 m². Before the fix the area was
        // 0.0, and `round(0.0 / 0.02, 2)` took the true branch and printed "0.00 m²" — a stated
        // denominator that was false, rather than an em-dash saying "not stated".
        ->and((float) $facts['denominator_sqm'])->toBe(6000.0);
});

it('names the mall on a receipt for a payment that settles no invoice', function () {
    // A cleared post-dated cheque lodged without an invoice produces a CAPTURED payment with zero
    // allocations — `lodgeSeries()` creates exactly that, and a year of cheques up front is the
    // Egyptian norm. The receipt resolved its property from the first allocated invoice, so it
    // named no mall and printed no issuer block. Both reachable surfaces are tenant-facing: the
    // portal's ViewPayment and `GET /api/v1/me/payments/{id}/receipt`.
    $tenant = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Retailer']);

    $payment = Payment::create([
        'tenant_id' => $tenant->id,
        'amount' => 5000,
        'method' => 'cheque',
        'status' => 'captured',
        'payment_date' => '2026-03-01',
    ]);

    // The cheque this payment came from. `PostDatedCheque::asset_id` is required on the lodging
    // form, so the property IS known — it was simply never consulted.
    PostDatedCheque::create([
        'reference' => 'PDC-2026-000123',
        'asset_id' => $this->asset->id,
        'tenant_id' => $tenant->id,
        'cheque_number' => '000123',
        'bank_name' => 'CIB',
        'amount' => 5000,
        'cheque_date' => '2026-03-01',
        'received_date' => '2026-01-01',
        'status' => 'cleared',
        'cleared_payment_id' => $payment->id,
    ]);

    expect($payment->invoices()->count())->toBe(0, 'The premise: this payment settles nothing yet.')
        ->and($payment->fresh()->originatingAssetId())->toBe($this->asset->id);

    // Through `viewData()` + the blade, NOT `build()`: mpdf returns a binary blob, so asserting
    // `%PDF` passes just as happily with a null asset — the template is null-safe. That is precisely
    // how the existing receipt tests stayed green through this bug.
    $data = app(ReceiptPdfService::class)->viewData($payment->fresh());
    $html = View::make('payments.receipt', $data)->render();

    expect($data['asset']?->name)->toBe('Atriom Walk')
        ->and($html)->toContain('Atriom Walk');
});

it('names the mall on a statement for a tenant who holds no lease', function () {
    // `TenantStatementPdfService` resolved the header property through `leases->first()->unit->asset`
    // and fell back to the invoices' own asset. A unit owner has no lease at all, so without the
    // fallback the statement of the money he actually owes carried no mall and no issuer.
    $unit = makeUnit($this->asset);
    $owner = makeTenant(['asset_id' => $this->asset->id, 'name' => 'Mona Fahmy']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    assessmentFor($ownership, $owner->id, 1000);

    expect($owner->fresh()->leases()->count())->toBe(0, 'The premise: an owner holds no lease.');

    $data = app(TenantStatementPdfService::class)->data($owner->fresh());

    expect($data['asset']?->name)->toBe('Atriom Walk');
});
