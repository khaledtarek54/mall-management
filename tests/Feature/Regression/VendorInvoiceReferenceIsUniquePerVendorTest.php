<?php

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorBillService;

/**
 * The same supplier invoice cannot be entered twice.
 *
 * THE GAP (validation sweep — payables, 2026-08-11). `vendor_bills.reference` holds "the vendor's
 * own invoice reference" (the migration says so) and carried **no uniqueness of any kind** — no DB
 * constraint, no model guard, no form rule. Entering a supplier's invoice twice — two people
 * keying the same paper, a re-import, a scan processed after someone already typed it — produced
 * two payables for one debt. Both approve, both pay, and the mall pays the supplier twice.
 *
 * This is the canonical AP control; a payables ledger without it is the one an auditor asks about
 * first. It is also the exact shape of the PDC cheque-number gap this sweep found in receivables,
 * which is worth noticing: both are "the counterparty's own document number", and neither had a
 * uniqueness rule because the SYSTEM's number (`number`, `reference` on the PDC) is generated and
 * unique, so the field looked handled.
 *
 * THE KEY is (vendor, reference), among non-**cancelled** bills:
 *
 *   - per VENDOR, not globally — two suppliers numbering their own invoices from 1 is normal;
 *   - CANCELLED excluded, so a mis-keyed bill can be cancelled and re-entered correctly. That
 *     carve-out is why this is a model guard and not a unique index;
 *   - a BLANK reference is exempt. Not every bill has the vendor's number to hand, and refusing a
 *     second blank would block ordinary entry. It is also the deliberate escape hatch: leave it
 *     empty rather than typing a placeholder.
 *
 * DEVIATION FROM YARDI, stated: Yardi warns on a duplicate invoice number and lets the operator
 * proceed. We refuse — consistent with the cheque-number decision earlier in this sweep, and for
 * the stronger reason that the failure here pays money out of the door rather than mis-forecasting
 * it coming in.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'APREF']);
    $this->vendor = Vendor::create([
        'name' => 'Cool Air Services', 'email' => 'ops@coolair.test',
    ]);
});

function duplicateRefBill(Vendor $vendor, $asset, array $attrs = []): VendorBill
{
    return VendorBill::create(array_merge([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'maintenance',
        'status' => 'draft',
        'bill_date' => '2026-08-01',
        'due_date' => '2026-08-31',
        'reference' => 'INV-4471',
        'subtotal' => 10000,
        'vat_amount' => 1400,
        'total' => 11400,
        'currency' => 'EGP',
    ], $attrs));
}

it('refuses a second bill carrying the same vendor invoice reference', function () {
    duplicateRefBill($this->vendor, $this->asset);

    expect(fn () => duplicateRefBill($this->vendor, $this->asset))
        ->toThrow(DomainException::class);

    expect(VendorBill::where('reference', 'INV-4471')->count())->toBe(1);
});

it('allows the same reference from a DIFFERENT vendor', function () {
    // Two suppliers numbering their own invoices from 1 is normal — the control that keeps the
    // refusal above from being a blanket ban.
    $other = Vendor::create([
        'name' => 'Bright Spark Electrical', 'email' => 'service@brightspark.test',
    ]);

    duplicateRefBill($this->vendor, $this->asset);

    expect(fn () => duplicateRefBill($other, $this->asset))->not->toThrow(DomainException::class);
});

it('allows a second bill with no reference at all', function () {
    // Not every bill arrives with the vendor's number to hand; refusing a second blank would
    // block ordinary entry.
    duplicateRefBill($this->vendor, $this->asset, ['reference' => null]);

    expect(fn () => duplicateRefBill($this->vendor, $this->asset, ['reference' => null]))
        ->not->toThrow(DomainException::class);
});

it('lets a CANCELLED bill\'s reference be re-entered (the mis-key correction path)', function () {
    $wrong = duplicateRefBill($this->vendor, $this->asset);
    app(VendorBillService::class)->cancel($wrong);

    expect(fn () => duplicateRefBill($this->vendor, $this->asset))->not->toThrow(DomainException::class);
});

it('refuses EDITING a bill onto a reference another live bill already holds', function () {
    duplicateRefBill($this->vendor, $this->asset, ['reference' => 'INV-4471']);
    $second = duplicateRefBill($this->vendor, $this->asset, ['reference' => 'INV-4472']);

    expect(fn () => $second->update(['reference' => 'INV-4471']))
        ->toThrow(DomainException::class);

    // Control: moving to a free reference still works, so the refusal is the duplicate and not
    // the bill's own field-immutability guard.
    expect(fn () => $second->update(['reference' => 'INV-4473']))
        ->not->toThrow(DomainException::class);
});

it('ignores case and surrounding whitespace, which is how the duplicate actually arrives', function () {
    // Two people keying the same paper do not produce byte-identical strings. A guard that only
    // caught the exact match would miss the realistic duplicate and give false assurance.
    duplicateRefBill($this->vendor, $this->asset, ['reference' => 'INV-4471']);

    expect(fn () => duplicateRefBill($this->vendor, $this->asset, ['reference' => ' inv-4471 ']))
        ->toThrow(DomainException::class);
});
