@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.income_statement_title'))

@section('content')
    @php
        $locale = $meta['locale'] ?? app()->getLocale();
        // The SAME shape the single-period statement prints, so a spread is this statement read
        // across more columns rather than a different report wearing its name.
        $shape = \App\Support\IncomeStatementLayout::shape((bool) $spread['has_below_the_line']);
        $spans = $spread['spans'];

        $cells = function (array $source) use ($spans) {
            $out = [];
            foreach ($spans as $span) {
                $out[] = number_format((float) ($source[$span['key']] ?? 0), 2);
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
        @foreach ($shape as $part)
            @php
                $rows = $part['is_net'] ? [] : array_values(array_filter(
                    $spread['rows'],
                    fn (array $row): bool => $row['section'] === $part['section']
                        && ($part['statement_section'] === null || $row['statement_section'] === $part['statement_section']),
                ));
                $totals = $spread['totals'][$part['totals_key']] ?? [];
            @endphp

            {{-- A below-the-line section with nothing in it prints nothing, exactly as on screen. --}}
            @continue($rows === [] && $part['optional'])

            @unless ($part['is_net'])
                <tr>
                    <td class="section-heading" colspan="{{ count($spans) + 2 }}">{{ $part['label'] }}</td>
                </tr>

                @foreach ($rows as $row)
                    <tr>
                        <td class="code">{{ $row['code'] }}</td>
                        <td>{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                        @foreach ($cells($row['amounts']) as $cell)
                            <td class="num">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            @endunless

            <tr class="{{ $part['is_net'] ? 'grand' : 'total-row' }}">
                <td colspan="2">{{ $part['is_net'] ? $part['label'] : $part['total_label'] }}</td>
                @foreach ($cells($totals) as $cell)
                    <td class="num">{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
