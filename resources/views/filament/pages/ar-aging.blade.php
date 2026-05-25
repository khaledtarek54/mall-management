<x-filament-panels::page>

    {{-- ============ Bucket picker + total ============ --}}
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem;">
            <div style="min-width: 14rem;">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="bucket" id="bucket">
                        @foreach($buckets as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                    {{ __('admin.reports.bucket') }}
                </p>
            </div>
            <div style="text-align: end;">
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                    {{ __('admin.reports.bucket_total') }}
                </div>
                <div style="margin-top: 0.375rem; font-size: 1.5rem; font-weight: 600; line-height: 1; color: rgb(220 38 38);">
                    EGP {{ number_format($totalBalance, 2) }}
                </div>
                <div style="margin-top: 0.25rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                    {{ $invoices->count() }} {{ __('admin.widgets.ar_aging.invoices') }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ Invoice listing ============ --}}
    <x-filament::section>
        @if($invoices->isEmpty())
            <div style="padding: 2rem; text-align: center; font-size: 0.875rem; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.no_invoices_in_bucket') }}
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="color: var(--fi-color-gray-500, #71717a); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.invoice.number') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.invoice.tenant') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.invoice.unit') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.invoice.due_date') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.tables.invoice.balance') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.reports.days_overdue') }}</th>
                        <th style="padding: 0.5rem 0.75rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        @php $days = (int) ($invoice->due_date?->diffInDays(now(), false) ?? 0); @endphp
                        <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                            <td style="padding: 0.75rem; font-family: ui-monospace, monospace; font-size: 0.75rem;">{{ $invoice->number }}</td>
                            <td style="padding: 0.75rem; font-weight: 500;">{{ $invoice->tenant?->name ?? '—' }}</td>
                            <td style="padding: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ $invoice->lease?->unit?->code ?? '—' }}</td>
                            <td style="padding: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ $invoice->due_date?->format('d/m/Y') }}</td>
                            <td style="padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums; font-weight: 600; color: rgb(220 38 38);">
                                EGP {{ number_format((float) $invoice->balance, 2) }}
                            </td>
                            <td style="padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums; color: {{ $days > 60 ? 'rgb(220 38 38)' : 'rgb(217 119 6)' }};">
                                {{ $days > 0 ? $days : 0 }}
                            </td>
                            <td style="padding: 0.75rem; text-align: end;">
                                <a
                                    href="{{ \App\Filament\Admin\Resources\Invoices\InvoiceResource::getUrl('edit', ['record' => $invoice]) }}"
                                    style="font-size: 0.75rem; font-weight: 500; color: var(--fi-color-primary-600, #d97706); text-decoration: none;"
                                >
                                    {{ __('admin.actions.view') }} →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

</x-filament-panels::page>
