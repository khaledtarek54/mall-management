{{--
    One section of a printed financial statement: its account lines, the chart's own subtotals
    (EG-28), then the figure the section foots to.

    Both the balance sheet and the income statement carried an identical `$lines` closure — two
    copies of one layout, which is exactly the drift the screen/CSV/PDF split invites. This is that
    layout once, and it groups through `App\Support\StatementGroups` like the other two renderers, so
    a statement, its export and its PDF cannot lay the same figures out three different ways.

    @param iterable $rows        account lines: code, name_en, name_ar, amount, account_id
    @param float    $total       what the section foots to
    @param string   $totalLabel
    @param string   $locale
    @param bool     $grouped     false for a statement whose sections are not chart branches
--}}
@php
    $groups = \App\Support\StatementGroups::for(collect($rows)->map(fn ($r): array => (array) $r)->all());
    $showGroups = ($grouped ?? true) && \App\Support\StatementGroups::worthShowing($groups);
@endphp
<table class="report">
    @foreach ($groups as $group)
        @foreach ($group['rows'] as $row)
            <tr>
                <td class="code" style="width:5rem">{{ $row['code'] }}</td>
                <td>{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                <td class="num">{{ number_format($row['amount'], 2) }}</td>
            </tr>
        @endforeach

        {{-- `show_subtotal` is false for the ungrouped bucket and for a one-row group. --}}
        @if ($showGroups && $group['show_subtotal'])
            <tr class="subtotal-row">
                <td></td>
                <td>{{ __('admin.reports.group_subtotal', ['group' => $locale === 'ar' ? $group['name_ar'] : $group['name_en']]) }}</td>
                <td class="num">{{ number_format($group['total'], 2) }}</td>
            </tr>
        @endif
    @endforeach

    <tr class="total-row">
        <td colspan="2">{{ $totalLabel }}</td>
        <td class="num">EGP {{ number_format($total, 2) }}</td>
    </tr>
</table>
