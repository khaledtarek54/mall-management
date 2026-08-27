{{--
    Who issued this document.

    Every value comes from `App\Support\IssuingEntity::forView()`, which every template that includes
    this already receives — the identity is the operator's one decision (`TaxSettings`), not
    something a template may name for itself. `PdfDocumentConformanceTest` fails the build on a
    template that hardcodes it, which is how five documents came to print "Atriom", the software's
    name, where the issuer belongs.

    Each optional line prints ONLY when configured. That is not tidiness — a plausible-looking
    registration number or billing address on a document a counterparty files is worse than a
    missing one, because it reads as valid and fails on audit rather than on issue.

    The trading name leads and the registered entity sits under it: a tenant reads "Atriom Walk" and
    knows which mall billed them, and may never have heard "Eltizam Property Management LLC".

    Optional `$issuerCaption` — a document that is ABOUT a property rather than FROM one (an owner
    statement) uses it to say which.
--}}
@include('partials.issuer-logo')

<div class="issuer-name">{{ $issuerName }}</div>

<div class="issuer-line" style="margin-top:3pt;">
    @if (! empty($sellerLegalName) && $sellerLegalName !== $issuerName)
        <div>{{ $sellerLegalName }}</div>
    @endif

    @if (! empty($issuerAddress))
        <div>{{ $issuerAddress }}</div>
    @endif

    @if (! empty($sellerTrn))
        {{-- A document titled "Tax Invoice" must carry the seller's registration number or the
             reader cannot claim the input VAT on it. See IssuingEntity::isTaxRegistered(), which
             is also what decides whether the title may say "Tax" at all. --}}
        <div><strong>{{ __('admin.pdf.seller_trn') }}</strong> {{ $sellerTrn }}</div>
    @endif

    @if (! empty($billingEmail))
        <div>{{ $billingEmail }}</div>
    @endif

    @if (! empty($issuerCaption))
        <div style="margin-top:4pt;">{{ $issuerCaption }}</div>
    @endif
</div>
