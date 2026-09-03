<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CreditNoteService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * GAP ANALYSIS — the credit-note cross-property guard failed OPEN for owner documents.
 *
 * `CreditNoteService::applyToInvoice()` refuses to settle one property's invoice with another
 * property's credit note. Its own comment states why that matters: the note's single GL entry
 * (Dr Sales Returns) is attributed to ONE property, and letting it cross means *"its owner is paid
 * a share on revenue that was credited back"*.
 *
 * The guard read the property through the LEASE:
 *
 *     $invoiceAssetId = $invoice->lease?->unit?->asset_id;
 *     $noteAssetId    = $note->lease?->unit?->asset_id;
 *     if ($noteAssetId !== null && $invoiceAssetId !== null && $noteAssetId !== $invoiceAssetId) …
 *
 * A unit owner's assessment has **no lease** (module 37 bills the ownership), so both sides resolve
 * to `null`, the `!== null` preconditions are false, and **the check is skipped entirely**. The
 * null-guard that was there to let a genuinely unscoped note bind on first apply became the hole:
 * for owner documents it is never not-null.
 *
 * Both tables have carried their own `asset_id` since the 2026-08-15 denormalisation, populated by
 * construction — `CreditNote` derives it in a `creating` hook, and `Invoice` has required it since
 * phase 2a. The guard was reading the inference the denormalisation exists to replace.
 *
 * Realistic and reachable: a party can own units in two malls, so both documents are ordinary.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->mallA = makeAsset(['code' => 'XA']);
    $this->mallB = makeAsset(['code' => 'XB']);

    // One party owning a shop in each mall — the ordinary shape, not a contrivance.
    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    foreach ([$this->mallA, $this->mallB] as $mall) {
        UnitOwnership::create([
            'asset_id' => $mall->id,
            'unit_id' => makeUnit($mall)->id,
            'tenant_id' => $this->owner->id,
            'status' => UnitOwnershipStatus::HandedOver->value,
            'started_at' => '2026-01-01',
            'payment_terms_days' => 10,
        ]);
    }
});

/** An assessment invoice raised against an ownership — no lease, by construction. */
function ownerAssessment(int $assetId, int $tenantId, int $ownershipId): Invoice
{
    return Invoice::create([
        'number' => 'INV-X-'.uniqid(),
        'asset_id' => $assetId,
        'lease_id' => null,
        'unit_ownership_id' => $ownershipId,
        'tenant_id' => $tenantId,
        'issue_date' => '2026-03-01',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'due_date' => '2026-03-11',
        'status' => 'issued',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
}

it('refuses to settle mall B\'s owner assessment with mall A\'s credit note', function () {
    $ownershipA = UnitOwnership::where('asset_id', $this->mallA->id)->sole();
    $ownershipB = UnitOwnership::where('asset_id', $this->mallB->id)->sole();

    $invoiceA = ownerAssessment($this->mallA->id, $this->owner->id, $ownershipA->id);
    $invoiceB = ownerAssessment($this->mallB->id, $this->owner->id, $ownershipB->id);

    // A note belonging to mall A — bound there by its own asset_id, as every note is.
    $note = CreditNote::create([
        'number' => 'CN-X-'.uniqid(),
        'asset_id' => $this->mallA->id,
        'invoice_id' => $invoiceA->id,
        'lease_id' => null,
        'tenant_id' => $this->owner->id,
        'issue_date' => '2026-03-05',
        'status' => 'issued',
        // `adjustment`, not free text: `credit_notes.reason` is a CLASSIFICATION registered in
        // `ValueSets`, and the wildcard saving listener refuses anything outside it. The prose
        // belongs in `notes`.
        'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'currency' => 'EGP',
    ]);

    expect(fn () => app(CreditNoteService::class)->applyToInvoice($note->fresh(), $invoiceB))
        ->toThrow(DomainException::class);

    // Refused, not partially applied: mall B's receivable is untouched.
    expect((float) $invoiceB->fresh()->balance)->toBe(5000.0);
});

it('still settles the owner\'s assessment in the note\'s OWN property — the control', function () {
    $ownershipA = UnitOwnership::where('asset_id', $this->mallA->id)->sole();
    $invoiceA = ownerAssessment($this->mallA->id, $this->owner->id, $ownershipA->id);

    $note = CreditNote::create([
        'number' => 'CN-X-'.uniqid(),
        'asset_id' => $this->mallA->id,
        'invoice_id' => $invoiceA->id,
        'lease_id' => null,
        'tenant_id' => $this->owner->id,
        'issue_date' => '2026-03-05',
        'status' => 'issued',
        // `adjustment`, not free text: `credit_notes.reason` is a CLASSIFICATION registered in
        // `ValueSets`, and the wildcard saving listener refuses anything outside it. The prose
        // belongs in `notes`.
        'reason' => 'adjustment',
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000,
        'currency' => 'EGP',
    ]);

    $applied = app(CreditNoteService::class)->applyToInvoice($note->fresh(), $invoiceA);

    // The guard must refuse only the crossing, never the legitimate apply.
    expect($applied)->toBe(2000.0)
        ->and((float) $invoiceA->fresh()->balance)->toBe(3000.0);
});
