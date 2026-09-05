<?php

namespace App\Services;

use App\Models\Lease;
use App\Support\DocumentText;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;

/**
 * The lease agreement itself — the document the parties sign (gap O1).
 *
 * A lease held an UPLOADED pdf and nothing generated one, so the terms an operator had just keyed
 * into the system had to be retyped into a Word file to produce the contract. That is the daily
 * workflow for anyone onboarding continuously, and it is where the two copies drift: the signed
 * paper says one rent and the system bills another, with nothing to reconcile them.
 *
 * **THE MONEY COMES FROM THE CHARGE SCHEDULE, NEVER FROM THE LEASE'S OWN COLUMNS.** This is the
 * whole reason the document is worth generating rather than typing. `MonthlyBillingService` reads
 * `charges`, so the schedule is what the tenant will actually be invoiced; `base_rent_monthly` and
 * its siblings are the negotiated headline and can differ from it — a relief window, an amendment
 * or a mid-term escalation all live in the schedule. A contract printed from the headline would be
 * a document that disagrees with the invoices raised under it, which is worse than no document.
 * The headline is printed too, beside the schedule, because that is what the parties agreed.
 *
 * **A DRAFT lease is watermarked**, for the reason the invoice's void stamp exists: an unexecuted
 * agreement and a signed one differ by a small status chip, and at arm's length a printed draft
 * reads as a contract. `Lease::status` is the only thing that says which this is.
 *
 * **It does NOT sign.** E-signature is the other half of O1 and is deliberately not attempted here:
 * it needs a provider, credentials, and a position on what an electronic signature is worth under
 * Egyptian law — decisions, not code. So the document ends in signature blocks for wet ink, which
 * is what these parties do today, and the uploaded counterpart remains where the executed copy
 * lives.
 */
class LeaseAgreementPdfService
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(Lease $lease): array
    {
        $lease->loadMissing([
            'tenant', 'unit.floor', 'unit.asset', 'units.floor',
            'charges', 'clauses', 'options',
        ]);

        // Every unit this lease lets. `units` is the many side — a lease can hold several shops,
        // and printing only `unit` would describe a smaller premises than the parties agreed.
        $units = $lease->units->isNotEmpty()
            ? $lease->units
            : collect([$lease->unit])->filter();

        $asset = $lease->unit?->asset ?? $units->first()?->asset;

        return [
            'lease' => $lease,
            'tenant' => $lease->tenant,
            'units' => $units,
            'asset' => $asset,
            'totalAreaSqm' => round((float) $units->sum('area_sqm'), 2),

            // WHAT WILL ACTUALLY BE BILLED — see the class docblock. Only the rows that are live:
            // an ended charge is history, and printing it as a term would misstate the agreement.
            'charges' => $lease->charges
                ->filter(fn ($charge) => $charge->is_active)
                ->sortBy('type')
                ->values(),

            'clauses' => $lease->clauses->sortBy('type')->values(),
            'options' => $lease->options->sortBy('type')->values(),

            // The landlord's particulars, from the property — SPREAD, because `pdf._issuer` reads
            // `$issuerName`/`$issuerAddress`/`$issuerLogo`/`$issuerCaption` as its own variables.
            // Passing the array under one key renders the shared masthead with none of them.
            ...IssuingEntity::forView($asset),

            // The operator's own standing wording — governing law, notices, whatever their lawyer
            // settled. No floor: an install that has not written it prints no block rather than a
            // heading over a gap, which on a contract would read as a missing term.
            'standingTerms' => DocumentText::for('lease.agreement_terms', $asset?->id),
        ];
    }

    public function build(Lease $lease, ?string $locale = null): string
    {
        return $this->document($lease, $locale)->render();
    }

    /**
     * Split from {@see build()} so a test can read the HTML this service really produces, rather
     * than re-wiring the same builder and proving only that the test agrees with itself.
     */
    public function document(Lease $lease, ?string $locale = null): PdfDocument
    {
        return PdfDocument::make('leases.pdf')
            // The TENANT is the recipient — a contract is addressed to the party signing it, so it
            // is written in their language unless the operator picks otherwise on the download.
            ->locale(DocumentLocale::resolve($locale, $lease->tenant))
            ->data(fn (): array => $this->viewData($lease))
            ->reference($lease->reference)
            ->bleed()
            ->watermark(fn (): ?string => $this->watermark($lease));
    }

    /**
     * The stamp across an agreement that is not in force.
     *
     * PUBLIC, unlike its sibling on the invoice, because it is the only way to test the decision:
     * the watermark is applied to MPDF (`SetWatermarkText`) and never reaches the HTML, so a test
     * reading `PdfDocument::html()` cannot see it. A first version asserted the draft label
     * appeared in the markup and passed — on the status CHIP in the masthead, which prints the
     * same words — so removing the draft from this list left it green. What a document is stamped
     * with is part of its contract, the same standing as {@see filename()}.
     *
     * A DRAFT is the case that matters: it is the state a lease is generated in, and an unsigned
     * draft handed across a desk is indistinguishable from an executed contract without it. The
     * terminal states are stamped for the mirror reason — a printed copy of a terminated lease
     * must not read as a live obligation.
     */
    public function watermark(Lease $lease): ?string
    {
        return in_array($lease->status, ['draft', 'pending_approval', 'terminated', 'cancelled', 'expired'], true)
            ? __("admin.statuses.lease.{$lease->status}")
            : null;
    }

    public function filename(Lease $lease): string
    {
        return $lease->reference.'.pdf';
    }
}
