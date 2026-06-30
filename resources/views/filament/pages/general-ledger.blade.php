<x-filament-panels::page>

    {{-- ============ Filters ============ --}}
    <x-filament::section>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div style="min-width: 22rem; flex: 1;">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="accountId" id="accountId">
                        <option value="">{{ __('admin.reports.choose_account') }}</option>
                        @foreach($accounts as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">
                    {{ __('admin.reports.account') }}
                </p>
            </div>
            <div style="min-width: 8rem;">
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
    </x-filament::section>

    {{-- ============ Account statement ============ --}}
    <x-filament::section>
        @if(! $account)
            <div style="padding: 2rem; text-align: center; font-size: 0.875rem; color: var(--fi-color-gray-500, #71717a);">
                {{ __('admin.reports.choose_account_hint') }}
            </div>
        @else
            <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="font-weight: 600;">{{ $account->code }} — {{ $locale === 'ar' ? $account->name_ar : $account->name_en }}</div>
                    <div style="font-size: 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.enums.ledger_account_type.' . $account->type) }}</div>
                </div>
                <div style="text-align: end;">
                    <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--fi-color-gray-500, #71717a);">{{ __('admin.reports.closing_balance') }}</div>
                    <div style="font-size: 1.25rem; font-weight: 600;">EGP {{ number_format($statement['closing'], 2) }}</div>
                </div>
            </div>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="color: var(--fi-color-gray-500, #71717a); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;">
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.fields.entry_date') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.tables.journal_entry.number') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: start;">{{ __('admin.fields.description') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.fields.debit') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.fields.credit') }}</th>
                        <th style="padding: 0.5rem 0.75rem; text-align: end;">{{ __('admin.reports.running_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6); font-style: italic; color: var(--fi-color-gray-500, #71717a);">
                        <td style="padding: 0.6rem 0.75rem;" colspan="5">{{ __('admin.reports.opening_balance') }}</td>
                        <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($statement['opening'], 2) }}</td>
                    </tr>
                    @foreach($statement['lines'] as $line)
                        <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                            <td style="padding: 0.6rem 0.75rem; color: var(--fi-color-gray-500, #71717a);">{{ \Illuminate\Support\Carbon::parse($line->entry_date)->format('d/m/Y') }}</td>
                            <td style="padding: 0.6rem 0.75rem; font-family: ui-monospace, monospace; font-size: 0.75rem;">{{ $line->entry_number }}</td>
                            <td style="padding: 0.6rem 0.75rem;">{{ $locale === 'ar' ? ($line->description_ar ?: $line->description_en) : ($line->description_en ?: $line->description_ar) }}</td>
                            <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}</td>
                            <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}</td>
                            <td style="padding: 0.6rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums; font-weight: 600;">{{ number_format($line->running_balance, 2) }}</td>
                        </tr>
                    @endforeach
                    @if($statement['lines']->isEmpty())
                        <tr style="border-top: 1px solid var(--fi-color-gray-100, #f3f4f6);">
                            <td style="padding: 1.5rem; text-align: center; color: var(--fi-color-gray-500, #71717a);" colspan="6">{{ __('admin.reports.no_movements') }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    </x-filament::section>

</x-filament-panels::page>
