{{--
    The credit note (إشعار دائن) — the invoice's mirror, and a tax document in its own right.

    Built on `pdf.layout` beside the invoice deliberately: these two documents describe the same
    money pointing opposite ways, a tenant files them together, and any difference in how they are
    set is a difference the reader has to account for. They were separate 300-line templates with
    separate copies of one stylesheet, and had already drifted.

    It carries the seller's registration number for the same reason the invoice does — the tenant
    uses THIS document to reverse input VAT they have already claimed, and cannot substantiate that
    against an unidentified supplier.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    [$chipBg, $chipInk] = T::bandChip($note->status);
    $outstanding = (float) $note->balance > 0.0;
@endphp

@extends('pdf.layout', ['title' => __('admin.pdf.credit_note').' '.$note->number])

@section('document')
    <div class="doc-type">{{ __('admin.pdf.credit_note') }}</div>
    <div class="doc-number">{{ Bidi::isolate($note->number) }}</div>
    <div>
        <span class="band-chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __("admin.statuses.credit_note.{$note->status}") }}
        </span>
    </div>
@endsection

@section('content')
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
                <div class="label">{{ __('admin.fields.credit_note_reason') }}</div>
                <div class="headline">{{ __("admin.enums.credit_note_reason.{$note->reason}") }}</div>
                @if($note->reason_notes)
                    {{-- Worded here, not when the credit was raised (UX-30) — the template runs
                         inside `DocumentLocale::in()`, so this is the reader's language. --}}
                    <div class="value">{{ Bidi::isolateLines($note->narrative()) }}</div>
                @endif
            </td>
            <td class="last" style="width:32%;">
                <div class="label" style="margin-bottom:5pt;">{{ __('admin.pdf.document_details') }}</div>
                <table class="pair">
                    <tr>
                        <td class="k">{{ __('admin.fields.issue_date') }}</td>
                        <td class="v">{{ $note->issue_date->format('d/m/Y') }}</td>
                    </tr>
                    {{-- The invoice this reverses. The single most important cross-reference on the
                         document: without it neither accountant can match the credit to the charge. --}}
                    <tr>
                        <td class="k">{{ __('admin.pdf.original_invoice') }}</td>
                        <td class="v">{{ $invoice?->number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="k">{{ __('admin.fields.currency') }}</td>
                        <td class="v">{{ $note->currency }}</td>
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
            @foreach($note->items as $item)
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

    {{-- Taxable value and tax, BY RATE — the same split the tax invoice carries, and needed here for
         the mirror-image reason. A credit note against a mixed invoice reverses exempt base rent and
         standard-rated service charge together; one "VAT" total leaves the tenant's accountant
         guessing how much input tax to give back. --}}
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

    <table class="totals-wrap" style="margin-top:12pt;">
        <tr>
            <td class="spacer"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="k">{{ __('admin.pdf.subtotal') }}</td>
                        <td class="v">{{ $note->currency }} {{ number_format((float) $note->subtotal, 2) }}</td>
                    </tr>
                    <tr class="rule">
                        <td class="k">{{ __('admin.pdf.vat') }}</td>
                        <td class="v">{{ $note->currency }} {{ number_format((float) $note->vat_amount, 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="k">{{ __('admin.pdf.total_credit') }}</td>
                        <td class="v">{{ $note->currency }} {{ number_format((float) $note->total, 2) }}</td>
                    </tr>
                    @if((float) $note->applied_amount > 0)
                        <tr>
                            <td class="k">{{ __('admin.tables.credit_note.applied') }}</td>
                            {{-- The minus sits AFTER the currency: a leading "−" is bidi-neutral at
                                 the start of the run and reorders to the far end of an Arabic line. --}}
                            <td class="v">{{ $note->currency }} −{{ number_format((float) $note->applied_amount, 2) }}</td>
                        </tr>
                        {{-- Credit still to be used reads as an amount in the tenant's FAVOUR, so it
                             is set as settled rather than as a debt — the invoice's red is reserved
                             for money owed, and using it here would say the opposite of the truth. --}}
                        <tr class="{{ $outstanding ? 'settled' : 'rule' }}">
                            <td class="k">{{ __('admin.pdf.balance') }}</td>
                            <td class="v">{{ $note->currency }} {{ number_format((float) $note->balance, 2) }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if($note->notes)
        <div class="panel" style="margin-top:20pt;">
            <div class="label">{{ __('admin.pdf.notes') }}</div>
            {{ Bidi::isolateLines($note->notes) }}
        </div>
    @endif
@endsection

@section('closing')
    {{ __('admin.pdf.credit_note_footer') }}
@endsection
