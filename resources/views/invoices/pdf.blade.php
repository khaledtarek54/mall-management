{{--
    The invoice — the one document every tenant reads every month and files with their own
    accountant.

    Built on `pdf.layout`, so the masthead, the palette, the type scale and the running footer are
    the shared ones; what is here is what makes this an invoice.

    The business rules this template carries, none of which are design decisions:
      · it may only call itself a TAX invoice when the issuer states a registration
        (`$documentTitleKey`, resolved in `InvoicePdfService::viewData()`);
      · a lease OR a unit ownership names the agreement — `invoices.lease_id` is nullable since
        module 37 and an owner's صيانة assessment has no lease;
      · the VAT split is printed BY RATE when an invoice carries more than one, because base rent is
        exempt while service charge is standard-rated and a single "VAT" total cannot be checked;
      · the payment instructions, terms and footer are the OPERATOR's words (`DocumentText`), and
        each block is drawn only when written — a heading over a gap on a document about money reads
        as a missing instruction rather than an absent one.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    [$chipBg, $chipInk] = T::bandChip($invoice->status);
    $settled = (float) $invoice->balance <= 0.0 && (float) $invoice->paid_amount > 0.0;
@endphp

@extends('pdf.layout', ['title' => __($documentTitleKey).' '.$invoice->number])

@section('document')
    <div class="doc-type">{{ __($documentTitleKey) }}</div>
    <div class="doc-number">{{ Bidi::isolate($invoice->number) }}</div>
    <div>
        <span class="band-chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __("admin.statuses.invoice.{$invoice->status}") }}
        </span>
    </div>
@endsection

@section('content')
    {{-- Who is billed · against what agreement · on what dates. One band: the shipped layout put
         the dates in the masthead and the billing period in a bar of its own below the parties,
         which split four related facts across three places. --}}
    <table class="facts gap-l">
        <tr>
            <td style="width:35%;">
                <div class="label">{{ __('admin.pdf.billed_to') }}</div>
                <div class="headline">{{ Bidi::isolate($tenant->name) }}</div>
                <div class="value">
                    @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                        <div>{{ Bidi::isolate($tenant->legal_name) }}</div>
                    @endif
                    @if($tenant->address)<div>{{ Bidi::isolate($tenant->address) }}</div>@endif
                    @if($tenant->tax_id)<div>{{ __('admin.pdf.tax_id') }} {{ Bidi::isolate($tenant->tax_id) }}</div>@endif
                    @if($tenant->email)<div>{{ Bidi::isolate($tenant->email) }}</div>@endif
                    @if($tenant->phone)<div>{{ Bidi::isolate($tenant->phone) }}</div>@endif
                </div>
            </td>
            <td style="width:33%;">
                {{-- A lease OR a unit ownership. --}}
                <div class="label">{{ $lease ? __('admin.pdf.lease_reference') : __('admin.pdf.ownership_reference') }}</div>
                <div class="headline">{{ Bidi::isolate($lease?->reference ?? ($ownership?->reference ?? '—')) }}</div>
                <div class="value">
                    <div>
                        {{ __('admin.pdf.unit') }} {{ Bidi::isolate($unit?->code ?? '—') }}@if($unit?->floor) · {{ __('admin.pdf.floor') }} {{ Bidi::isolate($unit->floor->code) }}@endif
                    </div>
                    @if($unit?->area_sqm)
                        <div>{{ number_format((float) $unit->area_sqm, 1) }} {{ __('admin.pdf.sqm') }}</div>
                    @endif
                    @if($lease?->commencement_date && $lease?->expiry_date)
                        <div>{{ __('admin.pdf.term') }} {{ $lease->commencement_date->format('d/m/Y') }} – {{ $lease->expiry_date->format('d/m/Y') }}</div>
                    @endif
                </div>
            </td>
            <td class="last" style="width:32%;">
                <div class="label" style="margin-bottom:5pt;">{{ __('admin.pdf.document_details') }}</div>
                <table class="pair">
                    <tr>
                        <td class="k">{{ __('admin.fields.issue_date') }}</td>
                        <td class="v">{{ $invoice->issue_date->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('admin.fields.due_date') }}</td>
                        <td class="v">{{ $invoice->due_date->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('admin.pdf.billing_period') }}</td>
                        <td class="v">{{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('admin.fields.currency') }}</td>
                        <td class="v">{{ $invoice->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:52%;">{{ __('admin.pdf.description') }}</th>
                <th class="num" style="width:17%;">{{ __('admin.pdf.amount') }}</th>
                <th class="num" style="width:12%;">{{ __('admin.pdf.vat_pct') }}</th>
                <th class="num" style="width:19%;">{{ __('admin.pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    {{-- The line is WORDED here, not when it was raised (UX-30): the whole
                         template renders inside `DocumentLocale::in()`, so this answers in the
                         language the document is being written in. --}}
                    <td class="ink">{{ Bidi::isolate($item->narrative()) }}</td>
                    <td class="num">{{ number_format((float) $item->amount, 2) }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->vat_rate, 2), '0'), '.') }}%</td>
                    <td class="num ink">{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Taxable value and tax, BY RATE. Suppressed on a single-rate invoice, where the totals block
         below already says it. --}}
    @if(count($vatSummary) > 1)
        <table class="items secondary" style="margin-top:14pt;">
            <thead>
                <tr>
                    <th style="width:52%;">{{ __('admin.pdf.vat_summary') }}</th>
                    <th class="num" style="width:17%;">{{ __('admin.pdf.taxable_value') }}</th>
                    <th class="num" style="width:12%;">{{ __('admin.pdf.vat_pct') }}</th>
                    <th class="num" style="width:19%;">{{ __('admin.pdf.vat') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vatSummary as $row)
                    <tr>
                        <td>{{ $row['rate'] > 0 ? __('admin.pdf.standard_rated') : __('admin.pdf.exempt_or_zero') }}</td>
                        <td class="num">{{ number_format($row['base'], 2) }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format($row['rate'], 2), '0'), '.') }}%</td>
                        <td class="num ink">{{ number_format($row['vat'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Right-aligned under the figures column the reader has just been reading down, rather than
         spanning the page. --}}
    <table class="totals-wrap" style="margin-top:12pt;">
        <tr>
            <td class="spacer"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="k">{{ __('admin.pdf.subtotal') }}</td>
                        <td class="v">{{ $invoice->currency }} {{ number_format((float) $invoice->subtotal, 2) }}</td>
                    </tr>
                    <tr class="rule">
                        <td class="k">{{ __('admin.pdf.vat') }}</td>
                        <td class="v">{{ $invoice->currency }} {{ number_format((float) $invoice->vat_amount, 2) }}</td>
                    </tr>
                    {{-- The tax-inclusive total, emphasised with a rule and weight rather than a
                         fill: the balance panel below is the loudest thing on this page, and two
                         competing blocks would leave the reader unsure which figure to act on. --}}
                    <tr class="subtotal">
                        <td class="k">{{ __('admin.pdf.total_due') }}</td>
                        <td class="v">{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</td>
                    </tr>
                    @if((float) $invoice->paid_amount > 0)
                        <tr>
                            <td class="k">{{ __('admin.pdf.paid') }}</td>
                            {{-- The minus sits AFTER the currency, not before it. A leading
                                 "−" is a bidi-neutral character at the start of the run, so in the
                                 Arabic document it reordered to the far end and the line read
                                 "EGP 25,000.00−". Between "EGP" and the digits it is bracketed by
                                 two left-to-right runs and stays where it was written, in both
                                 languages, with no markup. --}}
                            <td class="v">{{ $invoice->currency }} −{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Direction D's signature, and the reason the accent exists: the one figure the reader came
         for, set apart at a size nothing else on the page competes with. Drawn whether or not
         anything has been paid — a reader should never have to work out what they owe by
         subtracting one row from another.

         Settled reads GREEN and outstanding reads as money owed. The shipped template coloured the
         balance red whatever it was, so a fully paid invoice announced a debt of 0.00 in the colour
         of a demand. --}}
    <table class="balance">
        <tr>
            <td>
                <div class="label">{{ __('admin.pdf.balance') }}</div>
                <div class="caption">
                    {{ __('admin.fields.due_date') }} {{ $invoice->due_date->format('d/m/Y') }}
                </div>
            </td>
            {{-- THREE states, not two. The rewrite kept only "settled → green" and let everything
                 else fall through to the panel's default ink, so an invoice with nothing paid was
                 drawn exactly like one paid in full — on the loudest block of the page, which is
                 the one place a demand and a receipt must never look alike. --}}
            <td class="figure" style="color:{{ $settled ? T::SETTLED : ((float) $invoice->balance > 0 ? T::DUE : T::INK) }};">
                {{ $invoice->currency }} {{ number_format((float) $invoice->balance, 2) }}
            </td>
        </tr>
    </table>

    @if($invoice->notes)
        <div class="panel" style="margin-top:20pt;">
            <div class="label">{{ __('admin.pdf.notes') }}</div>
            {{ Bidi::isolateLines($invoice->notes) }}
        </div>
    @endif

    {{-- The e-invoicing reference block, gated on the MODULE rather than on the column alone
         (App\Support\Modules::FROZEN). Module 16 is frozen and uncertified, so a stored submission
         id is a MOCK one — printing it would state a tax-authority registration that does not
         exist, on the page a tenant files with their accountant. The columns are kept; only the
         claim is withheld. --}}
    @if(\App\Support\Modules::enabled('eta') && $invoice->eta_submission_id)
        <div class="panel accent">
            <div class="label">{{ __('admin.pdf.eta_reference') }}</div>
            <div>
                <strong>{{ __('admin.pdf.eta_submission_id') }}:</strong>
                <span class="mono">{{ $invoice->eta_submission_id }}</span>
            </div>
            @if($invoice->eta_long_id)
                <div>
                    <strong>{{ __('admin.pdf.eta_long_id') }}:</strong>
                    <span class="mono">{{ $invoice->eta_long_id }}</span>
                </div>
            @endif
            @if($invoice->eta_submitted_at)
                <div>
                    <strong>{{ __('admin.pdf.eta_submitted_at') }}:</strong>
                    {{ $invoice->eta_submitted_at->format('d/m/Y H:i') }}
                </div>
            @endif
        </div>
    @endif

    {{-- Where to pay, and on whose terms — the operator's own words (EG-15). Rendered side by side
         when both are written, because they are read together and stacking them pushes the terms
         onto a second page on a long invoice. --}}
    @php
        $paymentInstructions = \App\Support\DocumentText::for('invoice.payment_instructions', $invoice->asset_id);
        $terms = \App\Support\DocumentText::for('invoice.terms', $invoice->asset_id);
    @endphp

    @if($paymentInstructions || $terms)
        <table style="width:100%; border-collapse:collapse; margin-top:20pt;">
            <tr>
                @if($paymentInstructions)
                    {{-- The gutter is a CLASS, not an inline `$isRtl` ternary: a @section body is
                         evaluated before the layout renders, so it cannot see anything the layout
                         defines — and reaching for `$isRtl` here was an undefined-variable fatal on
                         every path that renders this template without the renderer. --}}
                    <td class="panel-pair{{ $terms ? '' : ' only' }}" style="width:{{ $terms ? '50%' : '100%' }};">
                        <div class="panel accent" style="margin-bottom:0;">
                            <div class="label">{{ __('admin.pdf.payment_instructions') }}</div>
                            {{-- `e()` INSIDE `nl2br`, never after: nl2br would otherwise have its
                                 own <br> escaped. The body is operator-typed. --}}
                            {!! nl2br(e(Bidi::isolateLines($paymentInstructions))) !!}
                        </div>
                    </td>
                @endif
                @if($terms)
                    <td class="panel-pair only" style="width:{{ $paymentInstructions ? '50%' : '100%' }};">
                        <div class="panel" style="margin-bottom:0;">
                            <div class="label">{{ __('admin.pdf.terms') }}</div>
                            {!! nl2br(e(Bidi::isolateLines($terms))) !!}
                        </div>
                    </td>
                @endif
            </tr>
        </table>
    @endif
@endsection

@section('closing')
    {!! nl2br(e(Bidi::isolateLines(\App\Support\DocumentText::for(
        'invoice.footer',
        $invoice->asset_id,
        ['days' => $invoice->issue_date->diffInDays($invoice->due_date)],
    ) ?? ''))) !!}
    {{-- Printed only when configured, exactly as the seller TRN is. A fabricated address is worse
         than none: it is trusted, written to, and fails silently. --}}
    @if($billingEmail) · {{ __('admin.pdf.footer_queries') }}: {{ $billingEmail }}@endif
@endsection
