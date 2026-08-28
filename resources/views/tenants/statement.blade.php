{{--
    The statement of account — every open invoice, every settlement, over a period.

    The longest document this system issues, and the one most likely to run to several pages, which
    is why the running footer (`App\Support\Pdf\PdfDocument`) carries the tenant's name and
    `page x of y`: a loose sheet of somebody's ledger with no name on it cannot be filed or
    challenged.

    The listings keep their own column widths — each one was measured against real content and the
    comments beside them record what broke at the previous value. What changed here is the shell:
    the masthead, palette and type scale are now the shared ones (`pdf.layout`), so this document and
    the invoices it lists are set in the same voice.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;
@endphp

@extends('pdf.layout', ['title' => __('admin.statement.title').' '.$tenant->name])

@section('document')
    <div class="doc-type">{{ __('admin.statement.title') }}</div>
    <div class="doc-meta" style="margin-top:4pt;">
        <table class="pair" style="width:auto; display:inline;">
            <tr>
                <td class="k">{{ __('admin.statement.as_of') }}</td>
                <td class="v"><strong>{{ $asOf->format('d/m/Y') }}</strong></td>
            </tr>
            <tr>
                <td class="k">{{ __('admin.statement.period_label') }}</td>
                <td class="v">{{ $since->format('d/m/Y') }} – {{ $asOf->format('d/m/Y') }}</td>
            </tr>
        </table>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td style="width:50%;">
                <div class="label">{{ __('admin.statement.tenant') }}</div>
                <div class="headline">{{ Bidi::isolate($tenant->name) }}</div>
                <div class="value">
                    @if($tenant->legal_name && $tenant->legal_name !== $tenant->name)
                        <div>{{ Bidi::isolate($tenant->legal_name) }}</div>
                    @endif
                    @if($tenant->tax_id)<div>{{ __('admin.pdf.tax_id') }} {{ Bidi::isolate($tenant->tax_id) }}</div>@endif
                    @if($tenant->email)<div>{{ Bidi::isolate($tenant->email) }}</div>@endif
                    @if($tenant->phone)<div>{{ Bidi::isolate($tenant->phone) }}</div>@endif
                </div>
            </td>
            <td class="last" style="width:50%;">
                <div class="label">{{ __('admin.statement.leases') }}</div>
                <div class="value">
                @forelse($tenant->leases->where('status', 'active') as $lease)
                    <div>
                        <strong>{{ Bidi::isolate($lease->reference) }}</strong> ·
                        {{ __('admin.pdf.unit') }} {{ Bidi::isolate($lease->unit?->code ?? '—') }} ·
                        {{ $lease->commencement_date->format('d/m/Y') }} – {{ $lease->expiry_date->format('d/m/Y') }}
                    </div>
                @empty
                    <div class="muted">—</div>
                @endforelse
                </div>
            </td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td>
                <div class="stat-label">{{ __('admin.statement.outstanding') }}</div>
                <div class="stat-value {{ $summary['outstanding'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['outstanding'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.overdue') }}</div>
                <div class="stat-value {{ $summary['overdue'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['overdue'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.total_billed') }}</div>
                <div class="stat-value">EGP {{ number_format($summary['total_billed'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.total_paid') }}</div>
                <div class="stat-value">EGP {{ number_format($summary['total_paid'], 2) }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">{{ __('admin.statement.open_invoices') }} ({{ $summary['open_count'] }})</div>
    @if($openInvoices->isEmpty())
        <div class="empty">{{ __('admin.statement.no_open_invoices') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:19%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.invoice.period') }}</th>
                    <th style="width:12%;">{{ __('admin.tables.invoice.due_date') }}</th>
                    <th class="num" style="width:13%;">{{ __('admin.tables.invoice.total') }}</th>
                    <th class="num" style="width:12%;">{{ __('admin.tables.invoice.paid') }}</th>
                    <th class="num" style="width:13%;">{{ __('admin.tables.invoice.balance') }}</th>
                    {{-- 8% could not hold "Partially paid": the header broke to "STATU S" and the pill
                         to "PARTIAL LY PAID" on the document the tenant receives. --}}
                    <th style="width:17%;">{{ __('admin.tables.common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($openInvoices as $inv)
                    <tr>
                        <td class="mono">{{ Bidi::isolate($inv->number) }}</td>
                        {{-- The SPAN, not the first month. This printed "Apr 2026" against a 240,300
                             quarterly invoice covering April–June, so the tenant reads a quarter's
                             rent as one month's and disputes it. Only a single-month period collapses
                             to one label. --}}
                        <td>{{ $inv->periodLabel() }}</td>
                        <td>{{ $inv->due_date->format('d/m/Y') }}</td>
                        <td class="num">{{ number_format((float) $inv->total, 2) }}</td>
                        <td class="num">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                        <td class="num {{ $inv->balance > 0 ? 'due' : 'settled' }}">{{ number_format((float) $inv->balance, 2) }}</td>
                        @php([$chipBg, $chipInk] = T::chip($inv->status))
                        <td><span class="chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">{{ __("admin.statuses.invoice.{$inv->status}") }}</span></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="num">{{ __('admin.statement.total_outstanding') }}</td>
                    {{-- Spans the balance AND status columns. The total carries an "EGP " prefix the
                         body cells do not, so it needs more room than the column it sits under — at
                         13% it wrapped to "EGP / 300,500.00" while every row above it fitted. --}}
                    <td colspan="2" class="num due">EGP {{ number_format((float) $openInvoices->sum('balance'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- Credits settle an invoice exactly as a payment does, and they were counted in Total Settled
         while appearing nowhere on the page. Only rendered when there are any: an empty "Credits"
         table on every ordinary statement is noise, and unlike payments a tenant does not expect
         one. --}}
    @if($credits->isNotEmpty())
        <div class="section-title">{{ __('admin.statement.credits_applied') }} ({{ $credits->count() }})</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.credit_note.number') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:20%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:30%;">{{ __('admin.fields.reason') }}</th>
                    <th class="num" style="width:18%;">{{ __('admin.tables.credit_note.applied') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($credits as $cn)
                    <tr>
                        <td class="mono">{{ Bidi::isolate($cn->number) }}</td>
                        <td>{{ $cn->issue_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="mono">{{ Bidi::isolate($cn->invoice?->number ?? '—') }}</td>
                        <td>{{ $cn->reason ? \App\Support\Translate::orFallback('admin.enums.credit_note_reason.'.$cn->reason, (string) $cn->reason) : '—' }}</td>
                        <td class="num settled">{{ number_format((float) $cn->applied_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="num">{{ __('admin.statement.total_credited') }}</td>
                    <td class="num settled">EGP {{ number_format((float) $credits->sum('applied_amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- The other two settlement channels (AR-GL-03). An invoice's balance falls through FOUR of
         them and this page listed two, so Total Settled could exceed Total Received with the
         difference itemised nowhere — worst on a final move-out statement, where netting the
         deposit is usually the largest single settlement the tenant will ever see.

         One table with a KIND column rather than two more: both answer the same question and carry
         the same four facts. Rendered only when there are any, for the reason the credits table
         gives — an empty section on every ordinary statement is noise. --}}
    @if($settlements->isNotEmpty())
        <div class="section-title">{{ __('admin.statement.other_settlements') }} ({{ $settlements->count() }})</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:26%;">{{ __('admin.statement.settlement_kind') }}</th>
                    <th style="width:14%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:20%;">{{ __('admin.tables.invoice.number') }}</th>
                    <th style="width:22%;">{{ __('admin.fields.notes') }}</th>
                    <th class="num" style="width:18%;">{{ __('admin.tables.credit_note.applied') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($settlements as $row)
                    <tr>
                        <td>{{ $row['kind'] }}</td>
                        <td>{{ $row['date']?->format('d/m/Y') ?? '—' }}</td>
                        <td class="mono">{{ Bidi::isolate($row['invoice'] ?? '—') }}</td>
                        <td>{{ Bidi::isolateLines($row['notes'] ?? '—') }}</td>
                        <td class="num settled">{{ number_format($row['amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="num">{{ __('admin.statement.total_other_settlements') }}</td>
                    <td class="num settled">EGP {{ number_format((float) $settlements->sum('amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="section-title">{{ __('admin.statement.recent_payments') }} ({{ $payments->count() }})</div>
    @if($payments->isEmpty())
        <div class="empty">{{ __('admin.statement.no_recent_payments') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:18%;">{{ __('admin.tables.payment.reference') }}</th>
                    <th style="width:18%;">{{ __('admin.tables.payment.date') }}</th>
                    <th style="width:18%;">{{ __('admin.tables.payment.method') }}</th>
                    <th class="num" style="width:18%;">{{ __('admin.tables.payment.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $p)
                    <tr>
                        <td class="mono">{{ Bidi::isolate($p->reference) }}</td>
                        <td>{{ $p->payment_date->format('d/m/Y') }}</td>
                        <td>{{ \App\Models\PaymentMethod::labelFor($p->method) }}</td>
                        <td class="num settled">{{ number_format((float) $p->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="num">{{ __('admin.statement.total_received') }}</td>
                    <td class="num settled">EGP {{ number_format((float) $payments->sum('amount'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

@endsection

@section('closing')
    {{ __('admin.statement.footer') }}@if($billingEmail) {{ __('admin.statement.footer_queries') }}: {{ Bidi::isolate($billingEmail) }}@endif
@endsection
