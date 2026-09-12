@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.income_statement_title'))

@section('content')
    @php
        $locale = $meta['locale'] ?? app()->getLocale();
        $spans = $spread['spans'];

        // The SAME lines the screen and the CSV print — `StatementSpread::records()`, handed in by
        // the PDF service and REQUIRED here. This template used to walk `$spread['rows']` itself and
        // so printed a flat list under each section while the screen beside it printed the chart's
        // headings and subtotals: one statement, laid out two ways depending on which button was
        // pressed. No fallback, deliberately — a second place that lays the spread out is that drift
        // waiting to come back.

        $cells = function (array $record) use ($spans) {
            $out = [];
            foreach ($spans as $span) {
                $value = $record['a_'.$span['key']] ?? null;
                // A heading carries no figure; its group's total is the subtotal beneath.
                $out[] = $value === null ? '' : number_format((float) $value, 2);
            }
            return $out;
        };
    @endphp

    <table class="report">
        <thead>
            <tr>
                <th class="code" style="width:5rem">{{ __('admin.tables.ledger_account.code') }}</th>
                <th>{{ __('admin.tables.ledger_account.account') }}</th>
                @foreach ($spans as $span)
                    <th class="num">{{ $span['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @php $section = null; @endphp
        @foreach ($records as $record)
            {{-- A section heading the first time a section's lines appear. A NET line (NOI, the
                 bottom line) closes no section of its own, so it never opens one either. --}}
            @if (! $record['is_net'] && $record['section'] !== $section)
                @php $section = $record['section']; @endphp
                <tr>
                    <td class="section-heading" colspan="{{ count($spans) + 2 }}">{{ $section }}</td>
                </tr>
            @endif

            @if ($record['is_net'])
                <tr class="grand">
                    <td colspan="2">{{ $record['account'] }}</td>
                    @foreach ($cells($record) as $cell)
                        <td class="num">{{ $cell }}</td>
                    @endforeach
                </tr>
            @elseif ($record['is_total'])
                <tr class="total-row">
                    <td colspan="2">{{ $record['account'] }}</td>
                    @foreach ($cells($record) as $cell)
                        <td class="num">{{ $cell }}</td>
                    @endforeach
                </tr>
            @elseif ($record['is_heading'])
                <tr class="group-heading">
                    <td class="code">{{ $record['code'] }}</td>
                    <td colspan="{{ count($spans) + 1 }}">{{ $record['account'] }}</td>
                </tr>
            @else
                <tr class="{{ $record['is_subtotal'] ? 'subtotal-row' : '' }}">
                    <td class="code">{{ $record['code'] }}</td>
                    <td>{{ $record['account'] }}</td>
                    @foreach ($cells($record) as $cell)
                        <td class="num">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>
@endsection
