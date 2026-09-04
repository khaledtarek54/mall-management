<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\InvoiceItem;
use App\Services\CamReconciliationService;
use App\Services\CamStatementPdfService;
use Carbon\CarbonImmutable;

/**
 * SW-170 — **the tenant's own statement must quote the figure the tenant is asked for.**
 *
 * A CAM true-up leaves the building as one of two documents, and both carry the pool's
 * `recovery_vat_rate` on top of the net figure: a positive true-up is recovered on an invoice
 * (`CamReconciliationService::billChargeImmediately()`), a negative one is credited on a credit
 * note (`billCredit()`). The reconciliation STATEMENT — the document RC-06 exists to give a tenant
 * exercising their audit right — summed the true-up, the management fee and the fee's VAT and
 * stopped, so its last line was not the amount on the invoice sent beside it.
 *
 * The column ships `default(14.00)`, so this was every pool on every install. The operator's own
 * Breakdown modal (`explainAllocation()`) had the number right the whole time, which is exactly why
 * nobody reconciled the two: the person who could see both was reading the modal.
 *
 * These assertions hold the statement against the REAL documents rather than against a second copy
 * of the arithmetic — that is the only comparison that can fail for the right reason.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A single-lease pool, reconciled and BILLED, so the statement can be compared with what was
 * actually raised. One lease at 100 m² makes the share 100%, so `allocated` == the pool total.
 */
function camStatementSettlement(float $actual, float $estimated, float $vatRate = 14, ?float $feePct = null): CamAllocation
{
    CarbonImmutable::setTestNow('2029-01-15');

    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2032-12-31',
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id,
        'period_year' => 2028,
        'status' => 'draft',
        'total_actual_expense' => $actual,
        'total_estimated_collected' => $estimated,
        'recovery_vat_rate' => $vatRate,
        'admin_fee_pct' => $feePct,
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    return $svc->bill($pool->allocations()->sole());
}

it('states a total due that equals the recovery invoice the tenant is sent', function () {
    // Pool 100,000, estimates 50,000 → a 50,000 shortfall, at the shipped 14% recovery rate,
    // with a 10% management fee on the capped cost (10,000 + 1,400 of its own VAT).
    $allocation = camStatementSettlement(100000, 50000, 14, 0.10);

    $facts = app(CamStatementPdfService::class)->facts($allocation->fresh());
    $invoice = InvoiceItem::where('charge_id', $allocation->billed_charge_id)->sole()->invoice;

    expect($facts['true_up'])->toBe(50000.0)
        ->and($facts['recovery_vat_rate'])->toBe(14.0)
        ->and($facts['recovery_vat'])->toBe(7000.0)                 // 50,000 × 14%
        // THE POINT. 50,000 + 7,000 + 10,000 + 1,400.
        ->and($facts['total_due'])->toBe(68400.0)
        ->and($facts['total_due'])->toBe((float) $invoice->total)
        // …and it is not the pre-fix figure, which omitted the recovery VAT entirely.
        ->and($facts['total_due'])->not->toBe(
            round($facts['true_up'] + $facts['admin_fee'] + $facts['admin_fee_vat'], 2)
        );
});

it('states a net credit that equals the credit note less the fee invoice raised beside it', function () {
    // Pool 20,000, estimates 100,000 → an 80,000 over-collection. The credit note carries the
    // recovery VAT too; the management fee cannot ride a credit, so it gets its own invoice.
    $allocation = camStatementSettlement(20000, 100000, 14, 0.10);

    $facts = app(CamStatementPdfService::class)->facts($allocation->fresh());
    $note = CreditNote::findOrFail($allocation->billed_credit_note_id);
    $feeInvoice = InvoiceItem::where('charge_id', $allocation->billed_admin_fee_charge_id)->sole()->invoice;

    expect($facts['true_up_is_credit'])->toBeTrue()
        ->and($facts['recovery_vat'])->toBe(11200.0)                // 80,000 × 14%
        ->and((float) $note->total)->toBe(91200.0)
        ->and((float) $feeInvoice->total)->toBe(2280.0)             // 2,000 fee + 280 VAT
        // THE POINT, on the side the template was doing the arithmetic for.
        ->and($facts['net_credit'])->toBe(88920.0)
        ->and($facts['net_credit'])->toBe(round((float) $note->total - (float) $feeInvoice->total, 2));
});

it('prints the VAT line on the document itself, not only in the facts', function () {
    // `facts()` can be right while the template stays silent — and on the credit side the template
    // was where the arithmetic lived, so this is the half that has to be read off the page.
    $svc = app(CamStatementPdfService::class);

    $recovery = $svc->document(camStatementSettlement(100000, 50000, 14, 0.10)->fresh(), 'en')->html();
    expect($recovery)->toContain('7,000.00')->toContain('68,400.00');

    $credit = $svc->document(camStatementSettlement(20000, 100000, 14, 0.10)->fresh(), 'en')->html();
    expect($credit)->toContain('11,200.00')->toContain('88,920.00');
});

it('leaves a pool that recovers no VAT exactly where it was — the deploy control', function () {
    // `recovery_vat_rate = 0` is the genuinely non-taxable pass-through. Nothing about such a pool
    // may move, or this becomes a change to what every existing statement says.
    $allocation = camStatementSettlement(100000, 50000, 0, 0.10);

    $facts = app(CamStatementPdfService::class)->facts($allocation->fresh());
    $invoice = InvoiceItem::where('charge_id', $allocation->billed_charge_id)->sole()->invoice;

    expect($facts['recovery_vat'])->toBe(0.0)
        ->and($facts['total_due'])->toBe(61400.0)                   // 50,000 + 10,000 + 1,400
        ->and($facts['total_due'])->toBe((float) $invoice->total);

    // …and the row does not print at all, rather than printing a 0.00 nobody needs.
    expect(app(CamStatementPdfService::class)->document($allocation->fresh(), 'en')->html())
        ->not->toContain('VAT on the shortfall');
});
