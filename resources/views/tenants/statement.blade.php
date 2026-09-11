{{--
    The statement of account — the tenant's ledger, printed (meeting 2026-09-02, points 5·6·8·9·10).

    Balance brought forward, then every movement in date order with a running balance — date ·
    reference · description · debit · credit · balance — the كشف حساب an Egyptian accountant
    reconciles from and the shape of Yardi's tenant statement. Every row comes from
    `App\Support\TenantLedger`, the same derivation the on-screen ledger tab shows, so the paper
    and the screen cannot disagree. Until 2026-09-11 this was a different document: four tables
    (open invoices, credits, payments, other settlements) with no running balance, a payment line
    that named its rail and nothing it settled, and the deposit HELD printed nowhere.

    Two sides, because an account has two: DUE FROM YOU (the closing balance) and HELD FOR YOU (the
    deposit, unapplied credit notes, credit on account). The deposit is a liability, not a
    receivable, so it has its own small account below the ledger and never enters the running
    balance. The open-invoice table closes the document as the balance's breakdown by document —
    the figure a tenant pays against — struck TODAY: on a statement bounded in the past it is dated
    beside a ledger that closes as at the date, rather than left to be noticed.

    The longest document this system issues, and the one most likely to run to several pages, which
    is why the running footer (`App\Support\Pdf\PdfDocument`) carries the tenant's name and
    `page x of y`: a loose sheet of somebody's ledger with no name on it cannot be filed or
    challenged. Column widths were measured against real content; the comments beside them record
    what broke at the previous value.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;
@endphp

@extends('pdf.layout', ['title' => __('admin.statement.title').' '.$tenant->name])

@section('document')
    <div class="doc-type">{{ __('admin.statement.title') }}</div>
    {{-- Plain lines, not a `.pair` table: those styles are scoped to `.facts`, so inside the band
         they resolve to nothing and the two dates run together. --}}
    <div class="doc-meta" style="margin-top:5pt;">
        <div>{{ __('admin.statement.as_of') }} <strong>{{ $asOf->format('d/m/Y') }}</strong></div>
        <div>{{ __('admin.statement.period_label') }} {{ $since->format('d/m/Y') }} – {{ $asOf->format('d/m/Y') }}</div>
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

    {{-- The two sides of the account. DUE FROM YOU is the ledger's closing balance — the amber
         figure, the one thing a tenant reads first; HELD FOR YOU is what the operator holds and has
         not netted, itemised on the line below so the tenant can see what makes it up. --}}
    <table class="summary">
        <tr>
            <td>
                <div class="stat-label">{{ __('admin.statement.due_from_you') }}</div>
                <div class="stat-value {{ $summary['due_from_tenant'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['due_from_tenant'], 2) }}</div>
            </td>
            <td>
                {{-- Overdue is struck TODAY (it is not replayed to the date); on a statement bounded
                     in the past it says so, beside a ledger that closes as at the date. --}}
                <div class="stat-label">{{ __('admin.statement.overdue') }}@if($figuresAsOfToday ?? false) — {{ __('admin.statement.figures_as_of', ['date' => $today->format('d/m/Y')]) }}@endif</div>
                <div class="stat-value {{ $summary['overdue'] > 0 ? 'warn' : '' }}">EGP {{ number_format($summary['overdue'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.due_to_you') }}</div>
                <div class="stat-value">EGP {{ number_format($summary['due_to_tenant'], 2) }}</div>
            </td>
            <td>
                <div class="stat-label">{{ __('admin.statement.deposit_held') }}</div>
                <div class="stat-value">EGP {{ number_format($summary['deposit_held'], 2) }}</div>
            </td>
        </tr>
    </table>
    @if($summary['credit_notes_unapplied'] > 0 || $summary['credit_on_account'] > 0)
        <div class="muted" style="font-size:8.5pt; margin:-4pt 0 8pt;">
            {{ __('admin.statement.due_to_you') }}:
            {{ __('admin.statement.deposit_held') }} {{ number_format($summary['deposit_held'], 2) }}
            @if($summary['credit_notes_unapplied'] > 0) · {{ __('admin.statement.credit_notes_unapplied') }} {{ number_format($summary['credit_notes_unapplied'], 2) }}@endif
            @if($summary['credit_on_account'] > 0) · {{ __('admin.statement.credit_on_account') }} {{ number_format($summary['credit_on_account'], 2) }}@endif
        </div>
    @endif

    {{-- ── The ledger ──────────────────────────────────────────────────────────────────────── --}}
    <div class="section-title">{{ __('admin.statement.account_ledger') }}</div>
    <table class="data">
        <thead>
            <tr>
                {{-- 14% on each money column: a seven-digit closing figure in bold needs 22.2mm
                     and a 12% cell had 19.7mm — measured by `StatementColumnsFitTest`. The
                     reference column keeps 18% (`INV-AW-202604-0001` needs 30.6mm) and the description gives up the rest. --}}
                <th style="width:11%;">{{ __('admin.fields.date') }}</th>
                <th style="width:18%;">{{ __('admin.fields.reference') }}</th>
                <th style="width:29%;">{{ __('admin.fields.description') }}</th>
                <th class="num" style="width:14%;">{{ __('admin.ledger.debit') }}</th>
                <th class="num" style="width:14%;">{{ __('admin.ledger.credit') }}</th>
                <th class="num" style="width:14%;">{{ __('admin.ledger.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            {{-- Balance forward, always — a zero says "nothing was owed going in", which is a
                 statement, where an absent line is a question. --}}
            <tr>
                <td>{{ $since->format('d/m/Y') }}</td>
                <td></td>
                <td><em>{{ __('admin.statement.balance_forward') }}</em></td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num {{ $ledger['opening'] > 0 ? 'due' : '' }}">{{ number_format($ledger['opening'], 2) }}</td>
            </tr>
            @forelse($ledger['rows'] as $row)
                <tr>
                    <td>{{ $row['date']?->format('d/m/Y') ?? '—' }}</td>
                    <td class="mono">{{ Bidi::isolate($row['reference'] ?: '—') }}</td>
                    <td>{{ Bidi::isolate($row['description']) }}</td>
                    <td class="num">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '' }}</td>
                    <td class="num settled">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '' }}</td>
                    <td class="num {{ $row['balance'] > 0 ? 'due' : '' }}">{{ number_format($row['balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted" style="text-align:center;">{{ __('admin.statement.no_movements') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            {{-- The currency is on the LABEL: an "EGP " prefix in a 12% cell wrapped a six-digit
                 closing balance to "EGP / 83,797.99" — the exact defect the open-invoice footer
                 below records for its own cell. --}}
            <tr>
                <td colspan="3" class="num">{{ __('admin.statement.closing_balance') }} (EGP)</td>
                <td class="num">{{ number_format((float) $ledger['rows']->sum('debit'), 2) }}</td>
                <td class="num settled">{{ number_format((float) $ledger['rows']->sum('credit'), 2) }}</td>
                <td class="num {{ $ledger['closing'] > 0 ? 'due' : 'settled' }}">{{ number_format($ledger['closing'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- ── The deposit account ─────────────────────────────────────────────────────────────── --}}
    {{-- A liability the operator holds, so it is its own small account and never enters the
         running balance above. Opens with what was held going into the window (derived from the
         pot, so the account always foots: opening + received − returned = held), lists the
         window's movements, and reads as one line when nothing is held — a tenant who paid a
         deposit expects to find it on their statement, and its absence reads as lost. --}}
    <div class="section-title">{{ __('admin.statement.deposit_account') }}</div>
    @if($deposit['rows']->isEmpty() && $deposit['held'] <= 0)
        <div class="empty">{{ __('admin.statement.no_deposit') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width:11%;">{{ __('admin.fields.date') }}</th>
                    <th style="width:18%;">{{ __('admin.fields.reference') }}</th>
                    <th style="width:29%;">{{ __('admin.fields.description') }}</th>
                    <th class="num" style="width:14%;">{{ __('admin.statement.deposit_in') }}</th>
                    <th class="num" style="width:14%;">{{ __('admin.statement.deposit_out') }}</th>
                    <th class="num" style="width:14%;"></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $since->format('d/m/Y') }}</td>
                    <td></td>
                    <td><em>{{ __('admin.statement.balance_forward') }}</em></td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">{{ number_format($deposit['opening'] ?? 0, 2) }}</td>
                </tr>
                @foreach($deposit['rows'] as $row)
                    <tr>
                        <td>{{ $row['date']->format('d/m/Y') }}</td>
                        <td class="mono">{{ Bidi::isolate($row['reference'] ?: '—') }}</td>
                        <td>{{ $row['kind'] }} · {{ Bidi::isolate($row['lease']) }}</td>
                        <td class="num">{{ $row['in'] > 0 ? number_format($row['in'], 2) : '' }}</td>
                        <td class="num">{{ $row['out'] > 0 ? number_format($row['out'], 2) : '' }}</td>
                        <td class="num"></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="num">{{ __('admin.statement.deposit_held') }} (EGP)</td>
                    <td class="num">{{ number_format((float) $deposit['rows']->sum('in'), 2) }}</td>
                    <td class="num">{{ number_format((float) $deposit['rows']->sum('out'), 2) }}</td>
                    <td class="num">{{ number_format($deposit['held'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- ── The balance, by document ────────────────────────────────────────────────────────── --}}
    <div class="section-title">{{ __('admin.statement.balance_by_invoice') }} ({{ $summary['open_count'] }})@if($figuresAsOfToday ?? false) — {{ __('admin.statement.figures_as_of', ['date' => $today->format('d/m/Y')]) }}@endif</div>
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
                        {{-- COLLECTABLE, not `balance`. A write-off deliberately leaves `balance`
                             standing, so quoting it here asks the tenant for money the operator
                             forgave and the ledger already expensed — and selecting on
                             `collectableBalance()` and then printing the raw figure is worse than
                             fixing neither. --}}
                        <td class="num {{ $inv->collectableBalance() > 0 ? 'due' : 'settled' }}">{{ number_format($inv->collectableBalance(), 2) }}</td>
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
                    <td colspan="2" class="num due">EGP {{ number_format((float) $openInvoices->sum(fn ($inv) => $inv->collectableBalance()), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
@endsection

@section('closing')
    {{-- The operator's own line (`DocumentText`, block `statement.footer`) — "valid for 7 days",
         whatever they wrote per property; the floor is the sentence this document always carried.
         `e()` INSIDE `nl2br`, or nl2br's own <br> gets escaped. --}}
    {!! nl2br(e(Bidi::isolateLines($footerText ?? __('admin.statement.footer')))) !!}@if($billingEmail) · {{ __('admin.statement.footer_queries') }}: {{ Bidi::isolate($billingEmail) }}@endif
@endsection
