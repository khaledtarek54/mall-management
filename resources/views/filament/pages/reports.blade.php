<x-filament-panels::page>

    {{-- ============ Period picker + actions ============ --}}
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem;">
            <div style="min-width: 14rem;">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="period" id="period">
                        @foreach($recentPeriods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                    {{ __('admin.reports.period') }}
                </p>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <x-filament::button
                    wire:click="downloadMonthlyClose"
                    icon="heroicon-o-arrow-down-tray"
                    color="primary"
                >
                    {{ __('admin.reports.download_monthly_close_pdf') }}
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="\App\Filament\Admin\Pages\ArAging::getUrl()"
                    icon="heroicon-o-arrow-trending-down"
                    color="gray"
                    outlined
                >
                    {{ __('admin.reports.ar_aging_drilldown') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ KPI grid ============ --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
        <x-filament::section>
            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.invoices_issued') }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.75rem; font-weight: 600; line-height: 1;">
                {{ number_format($report['invoices']['count']) }}
            </div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--fi-color-gray-500, #71717a);">
                EGP {{ number_format($report['invoices']['total'], 2) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.payments_captured') }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.75rem; font-weight: 600; line-height: 1; color: rgb(16 185 129);">
                {{ number_format($report['payments']['count']) }}
            </div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--fi-color-gray-500, #71717a);">
                EGP {{ number_format($report['payments']['total'], 2) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.collections_rate') }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.75rem; font-weight: 600; line-height: 1; color: {{ $report['collections_rate'] >= 80 ? 'rgb(16 185 129)' : 'rgb(217 119 6)' }};">
                {{ number_format($report['collections_rate'], 1) }}%
            </div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.of_invoiced') }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.outstanding_ar') }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.75rem; font-weight: 600; line-height: 1; color: rgb(220 38 38);">
                EGP {{ number_format($report['outstanding_total'], 0) }}
            </div>
            <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.as_of_close') }}
            </div>
        </x-filament::section>
    </div>

    {{-- ============ AR Aging buckets (clickable into drilldown) ============ --}}
    <x-filament::section :heading="__('admin.reports.ar_aging')">
        @php
            $bucketLabels = [
                'current' => __('admin.widgets.ar_aging.current'),
                'd_1_30' => __('admin.widgets.ar_aging.d_1_30'),
                'd_31_60' => __('admin.widgets.ar_aging.d_31_60'),
                'd_61_90' => __('admin.widgets.ar_aging.d_61_90'),
                'd_90_plus' => __('admin.widgets.ar_aging.d_90_plus'),
            ];
            $bucketColors = [
                'current' => 'rgb(16 185 129)',
                'd_1_30' => 'rgb(217 119 6)',
                'd_31_60' => 'rgb(217 119 6)',
                'd_61_90' => 'rgb(239 68 68)',
                'd_90_plus' => 'rgb(220 38 38)',
            ];
        @endphp
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem;">
            @foreach($report['ar_aging'] as $key => $row)
                <a
                    href="{{ \App\Filament\Admin\Pages\ArAging::getUrl(['bucket' => $key]) }}"
                    style="display: block; padding: 0.875rem 1rem; border: 1px solid var(--fi-color-gray-200, #e5e7eb); border-radius: 0.5rem; text-decoration: none; transition: border-color 0.15s;"
                    onmouseover="this.style.borderColor='var(--fi-color-primary-500, #f59e0b)'"
                    onmouseout="this.style.borderColor='var(--fi-color-gray-200, #e5e7eb)'"
                >
                    <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                        {{ $bucketLabels[$key] }}
                    </div>
                    <div style="margin-top: 0.375rem; font-size: 1.25rem; font-weight: 600; line-height: 1; color: {{ $bucketColors[$key] }};">
                        EGP {{ number_format($row['total'], 0) }}
                    </div>
                    <div style="margin-top: 0.25rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                        {{ $row['count'] }} {{ __('admin.widgets.ar_aging.invoices') }}
                    </div>
                </a>
            @endforeach
        </div>
    </x-filament::section>

    {{-- ============ Revenue by type ============ --}}
    @if(!empty($report['revenue_by_type']))
        <x-filament::section :heading="__('admin.reports.revenue_by_type')">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="text-align: start; color: var(--fi-color-gray-500, #71717a); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="padding: 0.5rem 0; text-align: start;">{{ __('admin.fields.type') }}</th>
                        <th style="padding: 0.5rem 0; text-align: end;">{{ __('admin.fields.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['revenue_by_type'] as $type => $amount)
                        <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                            <td style="padding: 0.625rem 0;">{{ __("admin.enums.invoice_item_type.{$type}") }}</td>
                            <td style="padding: 0.625rem 0; text-align: end; font-variant-numeric: tabular-nums; font-weight: 500;">
                                EGP {{ number_format($amount, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

</x-filament-panels::page>
