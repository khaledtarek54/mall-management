{{--
    The owner statement — the document Jawad actually receives: an account of his own money, rendered
    by his managing agent.

    Set in Direction D on the shared shell. The issuer is the OPERATOR who prepared it, not the
    property, which is named in the party block below — the owner is being told what his managing
    agent collected and spent on his behalf, and the agent's name is the one that belongs at the top.
--}}
@php
    use App\Support\Pdf\Bidi;
    use App\Support\Pdf\DocumentTheme as T;

    [$chipBg, $chipInk] = T::bandChip($statement->status);

    $fmt = fn ($v) => number_format((float) $v, 2).' '.($asset->currency ?? 'EGP');
    // The frozen per-account breakdown snapshotted at generate time; localized name per row.
    $breakdown = $run->income_breakdown ?? ['revenue' => [], 'expense' => []];
    $rowName = fn ($r) => $isRtl ? ($r['name_ar'] ?? $r['name_en'] ?? $r['code']) : ($r['name_en'] ?? $r['name_ar'] ?? $r['code']);
@endphp

@extends('pdf.layout', [
    'title' => __('admin.owner_statements.singular').' — '.$statement->reference,
    'issuerCaption' => __('admin.owner_statements.plural'),
])

@section('document')
    <div class="doc-type">{{ __('admin.owner_statements.singular') }}</div>
    <div class="doc-number">{{ Bidi::isolate($statement->reference) }}</div>
    <div class="doc-meta" style="margin-top:4pt;">
        {{ __('admin.owner_statements.fields.period') }}
        <strong>{{ \Illuminate\Support\Carbon::parse($run->period_start)->format('d M Y') }}
            – {{ \Illuminate\Support\Carbon::parse($run->period_end)->format('d M Y') }}</strong>
    </div>
    <div>
        <span class="band-chip" style="background:{{ $chipBg }}; color:{{ $chipInk }};">
            {{ __('admin.owner_statements.statuses.'.$statement->status) }}
        </span>
    </div>
@endsection

@section('content')
    <table class="facts gap-l">
        <tr>
            <td>
                <div class="label">{{ __('admin.owner_statements.fields.owner') }}</div>
                <div class="headline">{{ $owner?->name ?? '—' }}</div>
                <div class="value">{{ __('admin.owner_statements.fields.ownership_percentage') }}:
                    {{ number_format((float) $statement->ownership_percentage, 2) }}%</div>
            </td>
            <td>
                <div class="label">{{ __('admin.owner_statements.fields.property') }}</div>
                <div class="headline">{{ $asset->name }}</div>
                <div class="value">{{ $asset->city }}</div>
            </td>
        </tr>
    </table>

    {{-- Itemized property P&L — what the revenue was and where the expenses went, the whole point
         of a statement. Falls back to the bare totals for pre-snapshot (legacy) runs. --}}
    <table class="data">
        @if (! empty($breakdown['revenue']) || ! empty($breakdown['expense']))
            <tr class="section-row"><td colspan="2">{{ __('admin.owner_statements.pdf.revenue') }}</td></tr>
            @forelse ($breakdown['revenue'] as $r)
                <tr class="sub">
                    <td>{{ $rowName($r) }}</td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @empty
                <tr class="sub"><td>{{ __('admin.owner_statements.pdf.none') }}</td><td class="num">{{ $fmt(0) }}</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>{{ __('admin.owner_statements.fields.total_revenue') }}</td>
                <td class="num">{{ $fmt($run->total_revenue) }}</td>
            </tr>

            <tr class="section-row"><td colspan="2">{{ __('admin.owner_statements.pdf.expenses') }}</td></tr>
            @forelse ($breakdown['expense'] as $r)
                <tr class="sub">
                    <td>{{ $rowName($r) }}</td>
                    <td class="num">({{ $fmt($r['amount']) }})</td>
                </tr>
            @empty
                <tr class="sub"><td>{{ __('admin.owner_statements.pdf.none') }}</td><td class="num">({{ $fmt(0) }})</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>{{ __('admin.owner_statements.fields.total_expense') }}</td>
                <td class="num">({{ $fmt($run->total_expense) }})</td>
            </tr>
        @else
            <tr>
                <td>{{ __('admin.owner_statements.fields.total_revenue') }}</td>
                <td class="num">{{ $fmt($run->total_revenue) }}</td>
            </tr>
            <tr>
                <td>{{ __('admin.owner_statements.fields.total_expense') }}</td>
                <td class="num">({{ $fmt($run->total_expense) }})</td>
            </tr>
        @endif
        <tr class="net">
            <td>{{ __('admin.owner_statements.fields.net_operating_income') }}</td>
            <td class="num">{{ $fmt($run->net_operating_income) }}</td>
        </tr>
        @if ((float) $statement->weight < 0.999999)
            <tr class="sub">
                <td>{{ __('admin.owner_statements.fields.owner_share') }}
                    ({{ number_format((float) $statement->weight * 100, 2) }}%)</td>
                <td class="num">{{ $fmt($statement->owner_share) }}</td>
            </tr>
        @endif
        <tr class="sub">
            <td>{{ __('admin.owner_statements.fields.paid_to_date') }}</td>
            <td class="num">({{ $fmt($statement->paid_to_date) }})</td>
        </tr>
        <tr class="net">
            <td>{{ __('admin.owner_statements.fields.outstanding') }}</td>
            <td class="num">{{ $fmt($statement->outstanding()) }}</td>
        </tr>
    </table>

    <div class="closing-note">
        {{ __('admin.owner_statements.pdf.note') }}
    </div>
@endsection
