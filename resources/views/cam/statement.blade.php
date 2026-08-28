{{--
    The service-charge reconciliation statement — the working behind a true-up the tenant (or a unit
    owner) did not expect, which almost every commercial lease gives them the right to audit.

    Set in Direction D on the shared shell.
--}}
@php
    use App\Support\Pdf\Bidi;

    $money = fn ($v) => 'EGP '.number_format((float) $v, 2);
@endphp

@extends('pdf.layout', [
    'title' => __('admin.cam_statement.title').' '.$facts['year'],
    'issuerCaption' => $asset?->code,
])

@section('document')
    <div class="doc-type">{{ __('admin.cam_statement.title') }}</div>
    <div class="doc-number">{{ $facts['year'] }}</div>
    <div class="doc-meta" style="margin-top:4pt;">
        <div>{{ __('admin.tables.lease.reference') }} <strong>{{ Bidi::isolate($agreementReference ?? '—') }}</strong></div>
        <div>{{ __('admin.cam_statement.issued') }} {{ now()->format('d/m/Y') }}</div>
    </div>
@endsection

@section('content')
<table style="width:100%; border-collapse:collapse; margin-bottom:6px">
    <tr>
        <td style="width:50%; vertical-align:top">
            <div class="label">{{ __('admin.cam_statement.tenant') }}</div>
            <div class="headline">{{ $tenant?->name }}</div>
            <div class="value">{{ $unitCodes }}</div>
        </td>
        <td style="width:50%; vertical-align:top">
            <div class="label">{{ __('admin.cam_statement.premises') }}</div>
            <div class="value">{{ number_format($facts['area_sqm'], 2) }} m²</div>
        </td>
    </tr>
</table>

{{-- 1 · WHAT THE MALL SPENT. A tenant auditing the charge is entitled to know whether this number
     came out of the ledger or was typed in, so the statement says which. --}}
<div class="section-title">{{ __('admin.cam_statement.the_pool') }}</div>
<table class="data">
    <tr>
        <td class="k">{{ __('admin.cam_statement.pool_total', ['year' => $facts['year']]) }}</td>
        <td class="v">{{ $money($facts['pool_total']) }}</td>
    </tr>
</table>
<div class="basis">
    <strong>{{ __('admin.cam_statement.basis') }}:</strong>
    {{ $facts['expense_basis'] === 'ledger'
        ? __('admin.cam_statement.basis_ledger')
        : __('admin.cam_statement.basis_stated') }}
    @if (! empty($facts['ledger_accounts']))
        <ul>
            @foreach ($facts['ledger_accounts'] as $account)
                <li>{{ $account }}</li>
            @endforeach
        </ul>
    @endif
    @if (! empty($facts['exclusions']))
        <div style="margin-top:6px">
            <strong>{{ __('admin.cam_statement.exclusions') }}:</strong>
            {{ collect($facts['exclusions'])->implode(', ') }}
        </div>
    @endif
</div>

@if ($facts['gross_up_pct'] !== null
    && $facts['grossed_up_expense'] !== null
    && round($facts['grossed_up_expense'], 2) != round($facts['pool_total'], 2))
    {{-- The gross-up, shown ONLY when one actually changed the number. A no-op line on every
         statement in the mall is noise; a line that moved the tenant's bill is the one thing they
         are most likely to query. --}}
    <table class="data" style="margin-top:8px">
        <tr>
            <td class="k">
                {{ __('admin.cam_statement.grossed_up', ['pct' => rtrim(rtrim(number_format($facts['gross_up_pct'], 2), '0'), '.')]) }}
                <div class="note">{{ __('admin.cam_statement.grossed_up_note') }}</div>
            </td>
            <td class="v">{{ $money($facts['grossed_up_expense']) }}</td>
        </tr>
    </table>
@endif

{{-- 2 · HOW MUCH OF IT IS YOURS. The denominator is the number tenants argue about, so it is
     stated explicitly rather than left implicit in a percentage. --}}
<div class="section-title">{{ __('admin.cam_statement.your_share') }}</div>
<table class="data">
    <tr>
        <td class="k">{{ __('admin.cam_statement.your_area') }}</td>
        <td class="v">{{ number_format($facts['area_sqm'], 2) }} m²</td>
    </tr>
    <tr>
        <td class="k">
            {{ __('admin.cam_statement.denominator') }}
            {{-- The BASIS, named. A denominator without it is a number the tenant has to take on
                 trust, and "share of occupied area" and "share of gross leasable area" are very
                 different deals on a mall with vacancy.

                 Spelled out rather than built from the basis string: a dynamic key is exactly what
                 renders raw the day someone adds a fourth basis and forgets its label, which is
                 what TranslationCoverageTest exists to catch. --}}
            @php
                $basisNote = match ($facts['denominator_basis']) {
                    'gla' => __('admin.cam_statement.denominator_basis_gla'),
                    'fixed' => __('admin.cam_statement.denominator_basis_fixed'),
                    default => __('admin.cam_statement.denominator_basis_occupied'),
                };
            @endphp
            <div class="note">{{ $basisNote }}</div>
        </td>
        <td class="v">{{ $facts['denominator_sqm'] !== null ? number_format($facts['denominator_sqm'], 2) . ' m²' : '—' }}</td>
    </tr>
    <tr>
        <td class="k">
            {{ __('admin.cam_statement.share') }}
            @if ($facts['share_is_stated'])
                <div class="note">{{ __('admin.cam_statement.share_stated_note') }}</div>
            @endif
        </td>
        <td class="v">{{ number_format($facts['share_pct'], 4) }}%</td>
    </tr>
    <tr>
        <td class="k">{{ __('admin.cam_statement.allocated') }}</td>
        <td class="v">{{ $money($facts['allocated']) }}</td>
    </tr>
</table>

{{-- 3 · THE CAP. Shown only when one applied — a row reading "cap: none" on every statement in
     the mall trains everyone to skip the section that matters on the few where it bites. --}}
@if ($facts['cap_amount'] !== null)
    <div class="section-title">{{ __('admin.cam_statement.the_cap') }}</div>
    <table class="data">
        <tr>
            <td class="k">{{ __('admin.cam_statement.cap_ceiling') }}</td>
            <td class="v">{{ $money($facts['cap_amount']) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('admin.cam_statement.capped_cost') }}</td>
            <td class="v">{{ $money($facts['capped_cost']) }}</td>
        </tr>
        <tr>
            <td class="k">
                {{ __('admin.cam_statement.cap_absorbed') }}
                <div class="note">{{ __('admin.cam_statement.cap_absorbed_note') }}</div>
            </td>
            <td class="v">{{ $money($facts['cap_absorbed']) }}</td>
        </tr>
        @if ($facts['cap_scope'] === 'controllable')
            {{-- The scope, when it is not the whole share. A tenant reading a cap that did not bite
                 on their rates bill is entitled to know that was the deal, not an error. --}}
            <tr>
                <td class="k" colspan="2">
                    <div class="note">{{ __('admin.cam_statement.cap_scope_controllable') }}</div>
                </td>
            </tr>
        @endif
        @if ($facts['cap_headroom_used'] > 0)
            <tr>
                <td class="k">
                    {{ __('admin.cam_statement.cap_headroom_used') }}
                    <div class="note">{{ __('admin.cam_statement.cap_headroom_used_note') }}</div>
                </td>
                <td class="v">{{ $money($facts['cap_headroom_used']) }}</td>
            </tr>
        @elseif ($facts['cap_headroom_banked'] > 0)
            <tr>
                <td class="k">
                    {{ __('admin.cam_statement.cap_headroom_banked') }}
                    <div class="note">{{ __('admin.cam_statement.cap_headroom_banked_note') }}</div>
                </td>
                <td class="v">{{ $money($facts['cap_headroom_banked']) }}</td>
            </tr>
        @endif
    </table>
@endif

{{-- 4 · WHAT YOU ALREADY PAID, AND THE DIFFERENCE. --}}
<div class="section-title">{{ __('admin.cam_statement.settlement') }}</div>
<table class="data">
    <tr>
        <td class="k">{{ __('admin.cam_statement.cost_borne') }}</td>
        <td class="v">{{ $money($facts['capped_cost']) }}</td>
    </tr>
    <tr>
        <td class="k">
            {{ __('admin.cam_statement.estimates_paid') }}
            <div class="note">
                {{ $facts['estimate_basis'] === 'billed'
                    ? __('admin.cam_statement.estimates_billed_note')
                    : __('admin.cam_statement.estimates_stated_note') }}
            </div>
        </td>
        <td class="v">− {{ $money($facts['estimated_paid']) }}</td>
    </tr>
    <tr>
        <td class="k">
            {{ $facts['true_up_is_credit']
                ? __('admin.cam_statement.overpaid')
                : __('admin.cam_statement.underpaid') }}
        </td>
        <td class="v {{ $facts['true_up_is_credit'] ? 'credit' : '' }}">{{ $money(abs($facts['true_up'])) }}</td>
    </tr>
    @if ($facts['admin_fee'] > 0)
        <tr>
            <td class="k">
                {{ __('admin.cam_statement.admin_fee', ['pct' => rtrim(rtrim(number_format($facts['admin_fee_pct'], 2), '0'), '.')]) }}
                <div class="note">{{ __('admin.cam_statement.admin_fee_note') }}</div>
            </td>
            <td class="v">{{ $money($facts['admin_fee']) }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('admin.cam_statement.admin_fee_vat') }}</td>
            <td class="v">{{ $money($facts['admin_fee_vat']) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td class="k">
            {{ $facts['true_up_is_credit']
                ? __('admin.cam_statement.net_credit')
                : __('admin.cam_statement.net_due') }}
        </td>
        <td class="v">
            {{ $facts['true_up_is_credit']
                ? $money(abs($facts['true_up']) - $facts['admin_fee'] - $facts['admin_fee_vat'])
                : $money($facts['total_due']) }}
        </td>
    </tr>
</table>

@if ($facts['proposed_estimate'] !== null && $facts['proposed_estimate'] > 0)
    {{-- 5 · WHAT HAPPENS NEXT. Telling the tenant the new monthly figure on the same document that
         explains why it changed is the difference between a re-estimate and a surprise. --}}
    <div class="section-title">{{ __('admin.cam_statement.next_year') }}</div>
    <table class="data">
        <tr>
            <td class="k">
                {{ __('admin.cam_statement.proposed_estimate', ['year' => $facts['year'] + 1]) }}
                <div class="note">{{ __('admin.cam_statement.proposed_estimate_note') }}</div>
            </td>
            <td class="v">{{ $money($facts['proposed_estimate']) }}</td>
        </tr>
    </table>
@endif

<div class="closing-note">
    {{ __('admin.cam_statement.footer') }}
</div>
@endsection
