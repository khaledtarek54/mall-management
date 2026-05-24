<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-[14rem]">
                <label for="bucket" class="block text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('admin.reports.bucket') }}
                </label>
                <select
                    wire:model.live="bucket"
                    id="bucket"
                    class="mt-2 block w-full rounded-lg border-gray-300 bg-white py-2 ps-3 pe-10 text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    @foreach($buckets as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="text-right">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('admin.reports.bucket_total') }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">
                    EGP {{ number_format($totalBalance, 2) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $invoices->count() }} {{ __('admin.widgets.ar_aging.invoices') }}
                </div>
            </div>
        </div>
    </div>

    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if($invoices->isEmpty())
            <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('admin.reports.no_invoices_in_bucket') }}
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-start">{{ __('admin.tables.invoice.number') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('admin.tables.invoice.tenant') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('admin.tables.invoice.unit') }}</th>
                        <th class="px-4 py-3 text-start">{{ __('admin.tables.invoice.due_date') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('admin.tables.invoice.balance') }}</th>
                        <th class="px-4 py-3 text-end">{{ __('admin.reports.days_overdue') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($invoices as $invoice)
                        @php $days = (int) ($invoice->due_date?->diffInDays(now(), false) ?? 0); @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-gray-950 dark:text-white">{{ $invoice->number }}</td>
                            <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $invoice->tenant?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $invoice->lease?->unit?->code ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-end font-semibold tabular-nums text-red-600 dark:text-red-400">
                                EGP {{ number_format((float) $invoice->balance, 2) }}
                            </td>
                            <td class="px-4 py-3 text-end tabular-nums {{ $days > 60 ? 'text-red-600' : 'text-amber-600' }} dark:{{ $days > 60 ? 'text-red-400' : 'text-amber-400' }}">
                                {{ $days > 0 ? $days : 0 }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                <a
                                    href="{{ route('filament.admin.resources.invoices.edit', $invoice) }}"
                                    class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    {{ __('admin.actions.view') }} →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
