{{--
    Who issued this document — the left of the band.

    Every value comes from `App\Support\IssuingEntity::forView()`, which every template that includes
    this already receives: the identity is the operator's one decision (`TaxSettings`), not something
    a template may name for itself. `PdfDocumentConformanceTest` fails the build on a template that
    hardcodes it, which is how five documents came to print "Atriom", the software's name, where the
    issuer belongs.

    Each optional line prints ONLY when configured. That is not tidiness — a plausible-looking
    registration number or billing address on a document a counterparty files is worse than a missing
    one, because it reads as valid and fails on audit rather than on issue.

    The trading name leads and the registered entity sits under it: a tenant reads "Atriom Walk" and
    knows which mall billed them, and may never have heard "Eltizam Property Management LLC".

    Optional `$issuerCaption` — a document that is ABOUT a property rather than FROM one (an owner
    statement) uses it to say which.
--}}
@php use App\Support\Pdf\Bidi; @endphp

{{-- The logo is drawn for white paper; the band is navy. A plate rather than a reversed variant we
     cannot produce on the operator's behalf — a logo with a white bounding box would otherwise show
     it, and a dark monochrome one would vanish. --}}
@if (! empty($issuerLogo))
    <div style="margin-bottom:3mm;"><span class="logo-plate">@include('partials.issuer-logo')</span></div>
@endif

<div class="issuer-name">{{ Bidi::isolate($issuerName) }}</div>

<div class="issuer-line" style="margin-top:3pt;">
    @if (! empty($sellerLegalName) && $sellerLegalName !== $issuerName)
        <div>{{ Bidi::isolate($sellerLegalName) }}</div>
    @endif

    @if (! empty($issuerAddress))
        <div>{{ Bidi::isolate($issuerAddress) }}</div>
    @endif

    @if (! empty($sellerTrn))
        {{-- A document titled "Tax Invoice" must carry the seller's registration number or the
             reader cannot claim the input VAT on it. See IssuingEntity::isTaxRegistered(), which is
             also what decides whether the title may say "Tax" at all. --}}
        <div><strong>{{ __('admin.pdf.seller_trn') }}</strong> {{ Bidi::isolate($sellerTrn) }}</div>
    @endif

    @if (! empty($billingEmail))
        <div>{{ Bidi::isolate($billingEmail) }}</div>
    @endif

    {{-- Skipped when it would only repeat the name above it. An install with no registered seller
         name falls back to the property for BOTH, and "Atriom Walk / Atriom Walk" reads as a
         rendering fault rather than as a caption. --}}
    @if (! empty($issuerCaption) && $issuerCaption !== $issuerName)
        <div style="margin-top:4pt;">{{ Bidi::isolate($issuerCaption) }}</div>
    @endif
</div>
