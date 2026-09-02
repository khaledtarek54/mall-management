@extends('accounting.pdf.layout')
@section('report_title', __('admin.reports.income_statement_title'))

@section('content')
    @php
        $locale = $meta['locale'] ?? app()->getLocale();
        // The same sections, in the same order, the screen and the CSV use. A printed statement laid
        // out differently from the screen it was run from is the one copy that gets filed and argued
        // over, so all three read one description ({@see \App\Support\IncomeStatementLayout}).
        $sections = \App\Support\IncomeStatementLayout::sections($report);
    @endphp

    @foreach ($sections as $section)
        @if ($section['is_net'])
            {{-- NET OPERATING INCOME, and the bottom line: figures the sections above foot to,
                 printed as their own row rather than as a heading over an empty list. --}}
            <table class="report" style="margin-top:14px;">
                <tr class="grand">
                    <td>{{ $section['label'] }}</td>
                    <td class="num {{ $section['total'] >= 0 ? 'ok' : 'bad' }}">EGP {{ number_format($section['total'], 2) }}</td>
                </tr>
            </table>
        @else
            <div class="section-title">{{ $section['label'] }}</div>
            @include('accounting.pdf._statement-section', [
                'rows' => $section['rows'],
                'total' => $section['total'],
                'totalLabel' => $section['total_label'],
                'locale' => $locale,
            ])
        @endif
    @endforeach
@endsection
