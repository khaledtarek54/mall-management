<?php

use App\Enums\UnitOwnershipStatus;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\UnitOwnership;
use App\Services\SyncCamPoolFromLedgerService;

/**
 * SW-216 — the CAM billed estimate was gross of every credit note.
 *
 * On `estimate_basis = billed`, a pool derives what it already billed its participants by summing
 * their service-charge lines. That sum counted money the operator had given back.
 *
 * A FULLY credited invoice was at least caught by the `credited` status. **A PARTIAL credit moves no
 * status at all**, so no filter written at the invoice level could ever have seen one — and partial
 * is the common case: `CreditUnearnedBillingService::isTimeApportioned()` returns true for exactly a
 * monthly, not-in-arrears `service_charge`, which is what every mid-period move-out and every
 * mid-year resale credits. The pool believed it had collected money it had returned, and the annual
 * true-up under-charged by that amount — silently, per tenant, with the tie-out green throughout.
 *
 * The enabling fact was missing from the data: a credit note points at an INVOICE, and a pool needs
 * to know how much of a CHARGE came back. `credit_note_items.type` is that fact, added the way
 * `invoice_items.type` already works — `CreditNote::describeAs()`'s own docblock said in writing
 * that *"a credit-note line has no `type` column to derive from"*.
 *
 * Null means **not stated**, and an unstated line is not netted: apportioning a credit across line
 * types is a decision, not a derivation.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'CRB']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);
    $this->lease = makeLease($this->unit, makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'status' => 'active',
    ]);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'estimate_basis' => CamExpensePool::BASIS_BILLED,
        'total_actual_expense' => 0,
        'total_estimated_collected' => 0,
    ]);

    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => '2026-06-01',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    InvoiceItem::create([
        'invoice_id' => $this->invoice->id,
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => 30000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000,
    ]);
});

/** A credit note against the fixture's invoice, crediting `$type` for `$amount`. */
function creditOf(float $amount, ?string $type, string $status = 'issued'): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => test()->invoice->tenant_id,
        'lease_id' => test()->invoice->lease_id,
        'asset_id' => test()->invoice->asset_id,
        'invoice_id' => test()->invoice->id,
        'status' => $status,
        'issue_date' => '2026-06-15',
        'reason' => 'adjustment',
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount,
        'applied_amount' => 0, 'balance' => $amount, 'currency' => 'EGP',
    ]);

    $note->describeAs('Partial credit', $amount, 0, 0, null, $type);

    return $note;
}

it('nets a PARTIAL credit out of the billed estimate', function () {
    // The case no status filter could see: the invoice is still `issued`.
    creditOf(15000, 'service_charge');

    expect($this->invoice->fresh()->status)->not->toBe('credited');

    // Measured before: 30,000 — the whole invoice, as though nothing had been given back.
    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(15000.0)
        ->and(app(SyncCamPoolFromLedgerService::class)->estimateBilledFor($this->pool, $this->lease))->toBe(15000.0);
});

it('nets only the CHARGE the pool recovers, not everything credited', function () {
    // A credit note against the same invoice, for a different charge. A pool subtracts what IT
    // billed; a rent credit is none of its business, and matching on the invoice alone would have
    // taken it anyway.
    creditOf(9000, 'base_rent');

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30000.0);
});

it('does not net a credit that never said what it credited', function () {
    // Null is *not stated*. Every row written before the column existed is null, and guessing which
    // charge an old credit relieved is the apportionment this deliberately does not invent.
    creditOf(15000, null);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30000.0);
});

it('ignores a credit note that is not on the books', function () {
    // The same derivation `CreditNote::scopeOnTheBooks()` uses — a draft was never issued and a void
    // one was reversed, so neither gave anything back.
    creditOf(15000, 'service_charge', status: 'draft');
    creditOf(5000, 'service_charge', status: 'void');

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30000.0);
});

it('leaves an uncredited pool exactly as it was', function () {
    // The control. A netting that subtracted something from everything would satisfy the cases above
    // and quietly move every pool on the estate.
    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30000.0);
});

/**
 * ── The per-participant half ───────────────────────────────────────────────────────────────────
 *
 * The tests above all drive `estimateFromInvoices()`, the POOL's total — where the participant
 * clause is a no-op, so with one lease in the fixture the whole `->when($link !== [], …)` narrowing
 * could be deleted and every one of them would stay green. `estimateBilledFor()` is the half that
 * writes per-tenant money (`cam_allocations.estimated_paid`), and without the narrowing every
 * tenant would have every OTHER tenant's credits netted off their own share.
 */
it('nets each participant only their OWN credits', function () {
    $other = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'status' => 'active',
    ]);

    $otherInvoice = makeInvoice($other, [
        'status' => 'issued', 'issue_date' => '2026-06-01',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
    ]);
    InvoiceItem::create([
        'invoice_id' => $otherInvoice->id, 'type' => 'service_charge',
        'description' => 'Service charge', 'amount' => 30000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 30000,
    ]);

    // Only the FIRST lease is credited. The second was billed the same and credited nothing.
    creditOf(15000, 'service_charge');

    $sync = app(SyncCamPoolFromLedgerService::class);

    expect($sync->estimateBilledFor($this->pool, $this->lease->fresh()))->toBe(15000.0)
        ->and($sync->estimateBilledFor($this->pool, $other->fresh()))->toBe(30000.0);
});

/**
 * ── A UNIT OWNER is a participant too ──────────────────────────────────────────────────────────
 *
 * `participantOwnershipQuery()` is the other branch of the OR, and the reason it is scoped at all
 * (2026-09-02). An owner's صيانة invoice is credited exactly as a tenant's is.
 */
it('nets a unit OWNER’s credits off their own share', function () {
    $ownerUnit = makeUnit($this->asset, ['area_sqm' => 100]);
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $ownerUnit->id,
        'tenant_id' => makeTenant()->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'ownership_share_pct' => 100,
        'handover_date' => '2026-01-01',
        'purchase_price' => 1000000,
    ]);

    // Built directly rather than through `makeInvoice()`: an owner's invoice has NO lease, and a
    // finalised invoice's lease is immutable, so it cannot be re-homed after the fact.
    $ownerInvoice = Invoice::create([
        'unit_ownership_id' => $ownership->id,
        'tenant_id' => $ownership->tenant_id,
        'asset_id' => $this->asset->id,
        'status' => 'issued',
        'issue_date' => '2026-06-01', 'due_date' => '2026-06-10',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000,
        'paid_amount' => 0, 'balance' => 20000, 'currency' => 'EGP',
    ]);

    InvoiceItem::create([
        'invoice_id' => $ownerInvoice->id, 'type' => 'service_charge',
        'description' => 'Service charge', 'amount' => 20000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 20000,
    ]);

    $note = CreditNote::create([
        'tenant_id' => $ownerInvoice->tenant_id,
        'asset_id' => $ownerInvoice->asset_id,
        'invoice_id' => $ownerInvoice->id,
        'status' => 'issued',
        'issue_date' => '2026-06-15',
        'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'applied_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
    $note->describeAs('Owner credit', 5000, 0, 0, null, 'service_charge');

    expect(app(SyncCamPoolFromLedgerService::class)
        ->estimateBilledFor($this->pool, $ownership->fresh()))->toBe(15000.0);
});

/**
 * ── The two directions the FIRST pass of this fix got wrong ────────────────────────────────────
 *
 * Both were found in review, both by measuring rather than by reading.
 */
it('does not relieve a CREDITED invoice twice', function () {
    // The billed query used to exclude `credited` while `creditedBack()` did not — so a fully
    // credited invoice was dropped by one and subtracted by the other. Measured: **−30,000**, which
    // stores silently in a signed decimal and makes the true-up bill the credit back to the tenant.
    //
    // A SECOND, uncredited invoice is what makes this assertion able to fail. With the fixture's one
    // invoice the right answer is 0.00 and so is the double-relieved one — the floor below clamps
    // −30,000 to zero — so the test passed with the lists split, which is exactly the defect. Two
    // fixes that mask each other need a fixture where only one of them is doing the work.
    $second = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => '2026-07-01',
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
    ]);
    InvoiceItem::create([
        'invoice_id' => $second->id, 'type' => 'service_charge',
        'description' => 'Service charge', 'amount' => 50000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50000,
    ]);

    creditOf(30000, 'service_charge');
    $this->invoice->update(['status' => 'credited']);

    // 30,000 + 50,000 billed, 30,000 credited back. Relieved twice it reads 20,000.
    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(50000.0);
});

it('never reports a NEGATIVE estimate', function () {
    // Several partial credits, or an operator-typed credit line larger than the charge it relieves.
    // Nobody was ever billed a negative estimate, so the floor cannot hide a real figure.
    creditOf(20000, 'service_charge');
    creditOf(25000, 'service_charge');

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(0.0);
});
