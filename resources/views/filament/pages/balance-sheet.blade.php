<x-filament-panels::page>

    {{-- ============ Filters + balance check ============ --}}
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem;">
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <div style="min-width: 9rem;">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="year" id="year">
                            @foreach($years as $y)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.as_of_year_end') }}</p>
                </div>
                <div style="min-width: 14rem;">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="assetId" id="assetId">
                            @foreach($properties as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.property_scope') }}</p>
                </div>
            </div>
            <div style="text-align: end;">
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.balance_check') }}</div>
                <div style="margin-top: 0.375rem; font-size: 1.25rem; font-weight: 600; line-height: 1; color: {{ $report['balanced'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                    {{ $report['balanced'] ? '✓ ' . __('admin.reports.balanced') : '✗ ' . __('admin.reports.not_balanced') }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ Assets ============ --}}
    <x-filament::section :heading="__('admin.reports.assets')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['assets'], 'total' => $report['total_assets'], 'locale' => $locale, 'totalLabel' => __('admin.reports.total_assets')])
    </x-filament::section>

    {{-- ============ Liabilities + Equity ============ --}}
    <x-filament::section :heading="__('admin.reports.liabilities_equity')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['liabilities'], 'total' => $report['total_liabilities'], 'locale' => $locale, 'totalLabel' => __('admin.reports.total_liabilities')])

        <div style="height: 1rem;"></div>

        @include('filament.pages.partials.statement-lines', ['rows' => $report['equity'], 'total' => $report['total_equity'], 'locale' => $locale, 'totalLabel' => __('admin.reports.total_equity')])

        {{-- Net income for the period (not yet closed to retained earnings) --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem; margin-top: 0.5rem;">
            <tbody>
                <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                    <td style="padding: 0.5rem 0.75rem;" colspan="2">{{ __('admin.reports.net_income_period') }}</td>
                    <td style="padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($report['net_income'], 2) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr style="border-top: 2px solid var(--fi-color-gray-300, #d1d5db); font-weight: 700;">
                    <td style="padding: 0.6rem 0.75rem;" colspan="2">{{ __('admin.reports.total_equity_and_liabilities') }}</td>
                    <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">EGP {{ number_format($report['total_equity_and_liabilities'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </x-filament::section>

</x-filament-panels::page>
