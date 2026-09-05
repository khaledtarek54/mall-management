{{--
    The lease agreement — the document the parties sign.

    Built on `pdf.layout`, so the masthead, palette, type scale and running footer are the shared
    ones; what is here is what makes this a contract rather than a statement.

    The rules it carries, none of them design decisions:
      · the MONEY is the charge SCHEDULE, because that is what `MonthlyBillingService` bills — the
        negotiated headline is printed beside it, and a contract printed from the headline alone
        would disagree with the invoices raised under it (see LeaseAgreementPdfService);
      · a DRAFT or terminated lease is watermarked, so a printed copy cannot be mistaken for one in
        force;
      · the standing terms are the OPERATOR's words (`DocumentText`) and the block is drawn only
        when written — a heading over a gap on a contract reads as a missing term;
      · it ends in signature blocks for wet ink, because e-signature is deliberately not attempted.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    [$chipBg, $chipInk] = T::bandChip($lease->status);
    $money = fn (?float $v): string => 'EGP '.number_format((float) $v, 2);
    $date = fn ($d): string => $d
        ? \Carbon\CarbonImmutable::parse($d)->locale(app()->getLocale())->isoFormat('D MMMM YYYY')
        : '—';
@endphp

@extends('pdf.layout', ['title' => __('admin.pdf.lease_agreement').' '.$lease->reference])

@section('document')
    <div class="doc-type">{{ __('admin.pdf.lease_agreement') }}</div>
    <div class="doc-number">{{ Bidi::isolate($lease->reference) }}</div>
    <div>
        <span class="band-chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __("admin.statuses.lease.{$lease->status}") }}
        </span>
    </div>
@endsection

@section('content')
    {{-- THE PARTIES. The landlord block is the same one every other document prints, so a tenant
         reading a contract and an invoice sees one counterparty. --}}
    <table class="facts gap-l">
        <tr>
            <td style="width:50%;">
                <div class="label">{{ __('admin.pdf.landlord') }}</div>
                <div class="headline">{{ Bidi::isolate($issuerName ?? '') }}</div>
                <div class="value">
                    @if(filled($issuerAddress ?? null))
                        <div>{{ Bidi::isolateLines($issuerAddress) }}</div>
                    @endif
                </div>
            </td>
            <td class="last">
                <div class="label">{{ __('admin.pdf.tenant') }}</div>
                <div class="headline">{{ Bidi::isolate($tenant?->name ?? '') }}</div>
                <div class="value">
                    @if($tenant?->legal_name && $tenant->legal_name !== $tenant->name)
                        <div>{{ Bidi::isolate($tenant->legal_name) }}</div>
                    @endif
                    @if($tenant?->tax_id)
                        <div>{{ __('admin.pdf.trn') }}: {{ Bidi::isolate($tenant->tax_id) }}</div>
                    @endif
                    @if($tenant?->commercial_register)
                        <div>{{ __('admin.fields.commercial_register') }}: {{ Bidi::isolate($tenant->commercial_register) }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- THE PREMISES. Every unit, because a lease can hold several and naming one would describe a
         smaller premises than was agreed. --}}
    <div class="section-title">{{ __('admin.pdf.premises') }}</div>
    <table class="items gap-m">
        <thead>
            <tr>
                <th>{{ __('admin.resources.unit.singular') }}</th>
                <th>{{ __('admin.pdf.floor') }}</th>
                <th class="num">{{ __('admin.tables.unit.area') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($units as $unit)
                <tr>
                    <td>{{ Bidi::isolate($unit->code) }}</td>
                    <td>{{ Bidi::isolate($unit->floor?->name ?? '—') }}</td>
                    <td class="num">{{ number_format((float) $unit->area_sqm, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="subtotal">
                <td colspan="2">{{ __('admin.reports.totals') }}</td>
                <td class="num">{{ number_format($totalAreaSqm, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- THE TERM. --}}
    <div class="section-title">{{ __('admin.pdf.term') }}</div>
    <table class="facts gap-m">
        <tr>
            <td>
                <div class="label">{{ __('admin.fields.commencement_date') }}</div>
                <div class="value">{{ $date($lease->commencement_date) }}</div>
            </td>
            <td>
                <div class="label">{{ __('admin.fields.expiry_date') }}</div>
                <div class="value">{{ $date($lease->expiry_date) }}</div>
            </td>
            <td class="last">
                <div class="label">{{ __('admin.fields.term_months') }}</div>
                <div class="value">{{ $lease->term_months ? $lease->term_months : '—' }}</div>
            </td>
        </tr>
    </table>

    {{-- THE MONEY — the schedule that will actually be billed, not the headline. --}}
    <div class="section-title">{{ __('admin.pdf.rent_and_charges') }}</div>
    @if($charges->isEmpty())
        {{-- A lease with no schedule bills NOTHING, which is a fact about this agreement the
             parties should read here rather than discover at the first month-end. --}}
        <div class="empty gap-m">{{ __('admin.pdf.no_charge_schedule') }}</div>
    @else
        <table class="items gap-m">
            <thead>
                <tr>
                    <th>{{ __('admin.fields.type') }}</th>
                    {{-- A CHARGE frequency (monthly/quarterly/annually/one_time) is NOT the lease's
                         billing_frequency (…/semiannual/annual) — two sets, two maps, as the charge
                         schedule's own column says in writing. --}}
                    <th>{{ __('admin.charge_schedule.frequency') }}</th>
                    <th class="num">{{ __('admin.fields.amount') }}</th>
                    <th>{{ __('admin.fields.start_date') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($charges as $charge)
                    <tr>
                        <td>{{ Bidi::isolate($charge->name) }}</td>
                        <td>{{ __("admin.charge_schedule.frequencies.{$charge->frequency}") }}</td>
                        <td class="num">{{ $money($charge->amount) }}</td>
                        <td>{{ $date($charge->start_date) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- The negotiated headline, beside the schedule. Both are true and they answer different
         questions: what was agreed, and what is billed. --}}
    <table class="facts gap-l">
        <tr>
            <td>
                <div class="label">{{ __('admin.fields.base_rent_monthly') }}</div>
                <div class="value">{{ $money($lease->base_rent_monthly) }}</div>
            </td>
            <td>
                <div class="label">{{ __('admin.fields.security_deposit') }}</div>
                <div class="value">{{ $money($lease->security_deposit) }}</div>
            </td>
            <td class="last">
                <div class="label">{{ __('admin.fields.payment_terms_days') }}</div>
                <div class="value">{{ $lease->payment_terms_days ?? '—' }}</div>
            </td>
        </tr>
    </table>

    {{-- CLAUSES — the abstract, printed only when the lease has one. --}}
    @if($clauses->isNotEmpty())
        <div class="section-title">{{ __('admin.pdf.clauses') }}</div>
        <table class="items gap-m">
            <tbody>
                @foreach($clauses as $clause)
                    <tr>
                        <td style="width:28%;">{{ __("admin.enums.lease_clause_type.{$clause->type}") }}</td>
                        <td>{{ Bidi::isolateLines($clause->summary ?? '') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- The operator's standing wording. Drawn only when written — see the header. --}}
    @if(filled($standingTerms))
        <div class="section-title">{{ __('admin.pdf.terms') }}</div>
        <div class="note gap-m">{!! nl2br(e($standingTerms)) !!}</div>
    @endif
@endsection

@section('closing')
    {{-- Wet ink, because e-signature is deliberately not attempted here — it needs a provider,
         credentials and a position on what an electronic signature is worth under Egyptian law. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.pdf.landlord') }}</div>
            </td>
            <td class="last">
                <div class="sig-rule">&nbsp;</div>
                <div class="sig-caption">{{ __('admin.pdf.tenant') }}</div>
            </td>
        </tr>
    </table>
@endsection
