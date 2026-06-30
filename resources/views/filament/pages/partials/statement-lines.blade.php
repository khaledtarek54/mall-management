{{-- Reusable account-lines table for the financial statements.
     Expects: $rows (each: code, name_en, name_ar, amount), $total, $locale, $totalLabel --}}
@if($rows->isEmpty())
    <div style="padding: 1.5rem; text-align: center; font-size: 0.875rem; color: var(--fi-color-gray-500, #71717a);">
        {{ __('admin.reports.no_movements') }}
    </div>
@else
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <tbody>
            @foreach($rows as $row)
                <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                    <td style="padding: 0.5rem 0.75rem; font-family: ui-monospace, monospace; font-size: 0.75rem; width: 6rem; color: var(--fi-color-gray-500, #71717a);">{{ $row['code'] }}</td>
                    <td style="padding: 0.5rem 0.75rem;">{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                    <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid var(--fi-color-gray-300, #d1d5db); font-weight: 700;">
                <td style="padding: 0.6rem 0.75rem;" colspan="2">{{ $totalLabel }}</td>
                <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">EGP {{ number_format($total, 2) }}</td>
            </tr>
        </tfoot>
    </table>
@endif
