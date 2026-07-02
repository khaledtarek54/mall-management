<x-filament-panels::page>

    {{-- ============ Filters + net change ============ --}}
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
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.net_change_in_cash') }}</div>
                <div style="margin-top: 0.375rem; font-size: 1.5rem; font-weight: 700; line-height: 1; color: {{ $report['net_change'] >= 0 ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                    EGP {{ number_format($report['net_change'], 2) }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ Operating activities ============ --}}
    <x-filament::section :heading="__('admin.reports.operating_activities')">
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--fi-color-gray-100, #f3f4f6);">
            <span>{{ __('admin.reports.net_income') }}</span>
            <span style="font-variant-numeric: tabular-nums;">{{ number_format($report['net_income'], 2) }}</span>
        </div>
        <p style="padding: 0.5rem 0.75rem 0.25rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.working_capital_changes') }}</p>
        @include('filament.pages.partials.statement-lines', ['rows' => $report['adjustments'], 'total' => $report['operating_total'], 'locale' => $locale, 'totalLabel' => __('admin.reports.net_cash_operating')])
    </x-filament::section>

    {{-- ============ Investing activities ============ --}}
    <x-filament::section :heading="__('admin.reports.investing_activities')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['investing'], 'total' => $report['investing_total'], 'locale' => $locale, 'totalLabel' => __('admin.reports.net_cash_investing')])
    </x-filament::section>

    {{-- ============ Financing activities ============ --}}
    <x-filament::section :heading="__('admin.reports.financing_activities')">
        @include('filament.pages.partials.statement-lines', ['rows' => $report['financing'], 'total' => $report['financing_total'], 'locale' => $locale, 'totalLabel' => __('admin.reports.net_cash_financing')])
    </x-filament::section>

    {{-- ============ Reconciliation ============ --}}
    <x-filament::section>
        <div style="display: flex; justify-content: space-between; padding: 0.35rem 0.75rem;">
            <span>{{ __('admin.reports.cash_at_start') }}</span>
            <span style="font-variant-numeric: tabular-nums;">EGP {{ number_format($report['cash_opening'], 2) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.35rem 0.75rem;">
            <span>{{ __('admin.reports.net_change_in_cash') }}</span>
            <span style="font-variant-numeric: tabular-nums;">EGP {{ number_format($report['net_change'], 2) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0.75rem; border-top: 2px solid var(--fi-color-gray-300, #d1d5db); font-weight: 700; font-size: 1rem;">
            <span>{{ __('admin.reports.cash_at_end') }}</span>
            <span style="font-variant-numeric: tabular-nums;">EGP {{ number_format($report['cash_closing'], 2) }}</span>
        </div>
        <div style="margin-top: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; background: {{ $report['reconciled'] ? 'rgba(22,163,74,0.08)' : 'rgba(220,38,38,0.08)' }}; color: {{ $report['reconciled'] ? 'rgb(21 128 61)' : 'rgb(185 28 28)' }};">
            {{ $report['reconciled'] ? __('admin.reports.cash_flow_reconciled') : __('admin.reports.cash_flow_unreconciled') }}
        </div>
    </x-filament::section>

</x-filament-panels::page>
