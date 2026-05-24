<x-filament-panels::page>
    {{-- Period picker --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <label for="period" class="block text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('admin.reports.period') }}
                </label>
                <select
                    wire:model.live="period"
                    id="period"
                    class="mt-2 block min-w-[14rem] rounded-lg border-gray-300 bg-white py-2 ps-3 pe-10 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    @foreach($recentPeriods as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <x-filament::button
                    wire:click="downloadMonthlyClose"
                    icon="heroicon-o-arrow-down-tray"
                    color="primary"
                >
                    {{ __('admin.reports.download_monthly_close_pdf') }}
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.admin.pages.ar-aging')"
                    icon="heroicon-o-arrow-trending-down"
                    color="gray"
                    outlined
                >
                    {{ __('admin.reports.ar_aging_drilldown') }}
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.invoices_issued') }}
            </div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">
                {{ number_format($report['invoices']['count']) }}
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                EGP {{ number_format($report['invoices']['total'], 2) }}
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.payments_captured') }}
            </div>
            <div class="mt-1 text-3xl font-semibold text-emerald-600 dark:text-emerald-400">
                {{ number_format($report['payments']['count']) }}
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                EGP {{ number_format($report['payments']['total'], 2) }}
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.collections_rate') }}
            </div>
            <div class="mt-1 text-3xl font-semibold {{ $report['collections_rate'] >= 80 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                {{ number_format($report['collections_rate'], 1) }}%
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.of_invoiced') }}
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.outstanding_ar') }}
            </div>
            <div class="mt-1 text-3xl font-semibold text-red-600 dark:text-red-400">
                EGP {{ number_format($report['outstanding_total'], 0) }}
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.as_of_close') }}
            </div>
        </div>
    </div>

    {{-- AR Aging summary --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('admin.reports.ar_aging') }}
        </h3>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @php
                $bucketLabels = [
                    'current' => __('admin.widgets.ar_aging.current'),
                    'd_1_30' => __('admin.widgets.ar_aging.d_1_30'),
                    'd_31_60' => __('admin.widgets.ar_aging.d_31_60'),
                    'd_61_90' => __('admin.widgets.ar_aging.d_61_90'),
                    'd_90_plus' => __('admin.widgets.ar_aging.d_90_plus'),
                ];
                $bucketColors = [
                    'current' => 'text-emerald-600 dark:text-emerald-400',
                    'd_1_30' => 'text-amber-600 dark:text-amber-400',
                    'd_31_60' => 'text-amber-600 dark:text-amber-400',
                    'd_61_90' => 'text-red-500 dark:text-red-400',
                    'd_90_plus' => 'text-red-600 dark:text-red-500',
                ];
            @endphp
            @foreach($report['ar_aging'] as $key => $row)
                <a
                    href="{{ route('filament.admin.pages.ar-aging', ['bucket' => $key]) }}"
                    class="block rounded-lg border border-gray-200 p-3 transition hover:border-primary-500 dark:border-white/10 dark:hover:border-primary-500"
                >
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $bucketLabels[$key] }}
                    </div>
                    <div class="mt-1 text-xl font-semibold {{ $bucketColors[$key] }}">
                        EGP {{ number_format($row['total'], 0) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $row['count'] }} {{ __('admin.widgets.ar_aging.invoices') }}
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Revenue by type --}}
    @if(!empty($report['revenue_by_type']))
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('admin.reports.revenue_by_type') }}
        </h3>
        <table class="mt-4 w-full text-sm">
            <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2 text-start">{{ __('admin.fields.type') }}</th>
                    <th class="py-2 text-end">{{ __('admin.fields.amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach($report['revenue_by_type'] as $type => $amount)
                    <tr>
                        <td class="py-2 text-gray-950 dark:text-white">{{ __("admin.enums.invoice_item_type.{$type}") }}</td>
                        <td class="py-2 text-end font-medium tabular-nums text-gray-950 dark:text-white">
                            EGP {{ number_format($amount, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-filament-panels::page>
