<x-filament-panels::page>

    {{-- ============ Filters + net profit ============ --}}
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
                    <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.fiscal_year') }}</p>
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
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.net_profit') }}</div>
                <div style="margin-top: 0.375rem; font-size: 1.5rem; font-weight: 700; line-height: 1; color: {{ $report['net_profit'] >= 0 ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                    EGP {{ number_format($report['net_profit'], 2) }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ Revenue ============ --}}
    <x-filament::section :heading="__('admin.reports.revenue')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['revenue'], 'total' => $report['total_revenue'], 'locale' => $locale, 'totalLabel' => __('admin.reports.total_revenue')])
    </x-filament::section>

    {{-- ============ Expenses ============ --}}
    <x-filament::section :heading="__('admin.reports.expenses')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['expense'], 'total' => $report['total_expense'], 'locale' => $locale, 'totalLabel' => __('admin.reports.total_expenses')])
    </x-filament::section>

    {{-- ============ Net profit ============ --}}
    <x-filament::section>
        <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 1rem;">
            <span>{{ __('admin.reports.net_profit') }}</span>
            <span style="font-variant-numeric: tabular-nums; color: {{ $report['net_profit'] >= 0 ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">EGP {{ number_format($report['net_profit'], 2) }}</span>
        </div>
    </x-filament::section>

</x-filament-panels::page>
