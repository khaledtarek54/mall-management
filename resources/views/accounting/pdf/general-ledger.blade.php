@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.general_ledger_title'))

{{--
    The general ledger in print (the reports audit, 2026-09-12): one account's statement, or every
    account with movement in the window, each under its own heading with its opening balance, its
    lines in date order carrying a running balance, and its closing. `$statements` arrive resolved
    by the service — narratives already worded in the document's locale — so this template loops
    and holds no opinion about wording or ordering.
--}}
@section('content')
    @php
        $locale = $meta['locale'] ?? app()->getLocale();
        // Every bare figure is bidi-isolated: under an Arabic paragraph a leading minus is a
        // neutral the algorithm resolves against its neighbours, and `-338,003.70` printed as
        // `338,003.70-` — on exactly the abnormal-side balances an auditor reads first (found by
        // review, by extracting the text). The closing row carries "EGP " and needs no help.
        $num = fn (float $v): string => \App\Support\Pdf\Bidi::isolate(number_format($v, 2));
        $money = fn (float $v): string => $v > 0 ? $num($v) : '—';
    @endphp

    @forelse ($statements as $statement)
        <div class="section-title">{{ $statement['code'] }} — {{ $statement['name'] }}</div>
        <table class="report">
            <thead>
                <tr>
                    <th style="width:6.5rem">{{ __('admin.fields.entry_date') }}</th>
                    <th style="width:6rem">{{ __('admin.tables.journal_entry.number') }}</th>
                    <th>{{ __('admin.fields.description') }}</th>
                    <th class="num">{{ __('admin.fields.debit') }}</th>
                    <th class="num">{{ __('admin.fields.credit') }}</th>
                    <th class="num">{{ __('admin.reports.running_balance') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="subtotal-row">
                    <td></td>
                    <td></td>
                    <td>{{ __('admin.reports.opening_balance') }}</td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">{{ $num($statement['opening']) }}</td>
                </tr>
                @foreach ($statement['lines'] as $line)
                    <tr>
                        <td class="code">{{ \Illuminate\Support\Carbon::parse($line['entry_date'])->format('d/m/Y') }}</td>
                        <td class="code">{{ $line['entry_number'] }}</td>
                        {{-- Operator-typed on a manual entry, so it keeps its own direction. --}}
                        <td>{{ \App\Support\Pdf\Bidi::isolate($line['description']) }}</td>
                        <td class="num">{{ $money($line['debit']) }}</td>
                        <td class="num">{{ $money($line['credit']) }}</td>
                        <td class="num">{{ $num($line['running_balance']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td></td>
                    <td></td>
                    <td>{{ __('admin.reports.closing_balance') }}</td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">EGP {{ number_format($statement['closing'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <p>{{ __('admin.reports.no_movements') }}</p>
    @endforelse
@endsection
