<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->report())

    <div style="overflow-x:auto;" class="mt-6">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <thead>
                <tr style="border-bottom:1px solid #e5e7eb;">
                    <th style="padding:.5rem;text-align:start;">{{ __('admin.tax_depreciation.table.pool') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.rate') }}</th>
                    <th style="padding:.5rem;text-align:start;">{{ __('admin.tax_depreciation.table.basis') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.opening') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.additions') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.disposals') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.base') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.depreciation') }}</th>
                    <th style="padding:.5rem;text-align:end;">{{ __('admin.tax_depreciation.table.closing') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['pools'] as $pool)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:.5rem;">{{ __("admin.tax_depreciation.pools.{$pool['pool']}") }}</td>
                        <td style="padding:.5rem;text-align:end;">{{ $pool['rate'] }}%</td>
                        <td style="padding:.5rem;">
                            {{ $pool['pooled']
                                ? __('admin.tax_depreciation.pooled')
                                : __('admin.tax_depreciation.straight_line') }}
                        </td>
                        <td style="padding:.5rem;text-align:end;">{{ number_format($pool['opening'], 2) }}</td>
                        <td style="padding:.5rem;text-align:end;">{{ number_format($pool['additions'], 2) }}</td>
                        <td style="padding:.5rem;text-align:end;">{{ number_format($pool['disposals'], 2) }}</td>
                        <td style="padding:.5rem;text-align:end;">{{ number_format($pool['base'], 2) }}</td>
                        <td style="padding:.5rem;text-align:end;font-weight:600;">{{ number_format($pool['depreciation'], 2) }}</td>
                        <td style="padding:.5rem;text-align:end;">{{ number_format($pool['closing'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="padding:1rem;text-align:center;color:#9ca3af;">
                        {{ __('admin.tax_depreciation.empty') }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- The comparison is the reason the page exists: the gap between the two bases is the
         temporary difference that carries into deferred tax. --}}
    <div class="mt-6" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;">
        <div style="padding:.75rem 1rem;border:1px solid #e5e7eb;border-radius:.5rem;">
            <div style="font-size:.75rem;color:#6b7280;">{{ __('admin.tax_depreciation.tax_total') }}</div>
            <div style="font-size:1.25rem;font-weight:700;">EGP {{ number_format($report['tax_total'], 2) }}</div>
        </div>
        <div style="padding:.75rem 1rem;border:1px solid #e5e7eb;border-radius:.5rem;">
            <div style="font-size:.75rem;color:#6b7280;">{{ __('admin.tax_depreciation.book_total') }}</div>
            <div style="font-size:1.25rem;font-weight:700;">EGP {{ number_format($report['book_total'], 2) }}</div>
        </div>
        <div style="padding:.75rem 1rem;border:1px solid #fcd34d;background:#fffbeb;border-radius:.5rem;">
            <div style="font-size:.75rem;color:#92400e;">{{ __('admin.tax_depreciation.difference') }}</div>
            <div style="font-size:1.25rem;font-weight:700;color:#92400e;">EGP {{ number_format($report['difference'], 2) }}</div>
            <div style="font-size:.75rem;color:#92400e;">{{ __('admin.tax_depreciation.difference_hint') }}</div>
        </div>
    </div>
</x-filament-panels::page>
