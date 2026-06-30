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
                    <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                        {{ __('admin.reports.fiscal_year') }}
                    </p>
                </div>
                <div style="min-width: 14rem;">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="assetId" id="assetId">
                            @foreach($properties as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                        {{ __('admin.reports.property_scope') }}
                    </p>
                </div>
            </div>
            <div style="text-align: end;">
                <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">
                    {{ __('admin.reports.balance_check') }}
                </div>
                <div style="margin-top: 0.375rem; font-size: 1.25rem; font-weight: 600; line-height: 1; color: {{ $report['balanced'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                    {{ $report['balanced'] ? '✓ ' . __('admin.reports.balanced') : '✗ ' . __('admin.reports.not_balanced') }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============ Trial balance table ============ --}}
    <x-filament::section>
        @if($report['rows']->isEmpty())
            <div style="padding: 2rem; text-align: center; font-size: 0.875rem; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.no_movements') }}
            </div>
        @else
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="color: var(--fi-color-gray-500, #71717a); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.ledger_account.code') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.ledger_account.account') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.fields.debit') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.fields.credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['rows'] as $row)
                        <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                            <td style="padding: 0.6rem 0.75rem; font-family: ui-monospace, monospace; font-size: 0.75rem;">{{ $row['code'] }}</td>
                            <td style="padding: 0.6rem 0.75rem; font-weight: 500;">{{ $locale === 'ar' ? $row['name_ar'] : $row['name_en'] }}</td>
                            <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">
                                {{ $row['debit_balance'] > 0 ? number_format($row['debit_balance'], 2) : '—' }}
                            </td>
                            <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">
                                {{ $row['credit_balance'] > 0 ? number_format($row['credit_balance'], 2) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top: 2px solid var(--fi-color-gray-300, #d1d5db); font-weight: 700;">
                        <td style="padding: 0.75rem;" colspan="2">{{ __('admin.reports.totals') }}</td>
                        <td style="padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">EGP {{ number_format($report['total_debit'], 2) }}</td>
                        <td style="padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">EGP {{ number_format($report['total_credit'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </x-filament::section>

</x-filament-panels::page>
