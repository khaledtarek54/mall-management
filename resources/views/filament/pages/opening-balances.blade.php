<x-filament-panels::page>
    <form wire:submit="preview">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit" color="gray">
                {{ __('admin.opening_balances.actions.preview') }}
            </x-filament::button>

            {{-- Import is offered only once a preview has BALANCED. The operator should never be
                 able to commit a trial balance they have not been shown. --}}
            @if (($preview['balanced'] ?? false) === true)
                <x-filament::button type="button" wire:click="import">
                    {{ __('admin.opening_balances.actions.import') }}
                </x-filament::button>
            @endif
        </div>
    </form>

    @if ($preview !== null)
        <div class="mt-6">
            @if (! empty($preview['errors']))
                <div class="fi-section p-4 mb-4" style="border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:.5rem;">
                    <ul style="margin:0;padding-inline-start:1.25rem;font-size:.875rem;line-height:1.6;">
                        @foreach ($preview['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                    <thead>
                        <tr style="text-align:start;border-bottom:1px solid #e5e7eb;">
                            <th style="padding:.5rem;text-align:start;">{{ __('admin.opening_balances.table.account') }}</th>
                            <th style="padding:.5rem;text-align:end;">{{ __('admin.opening_balances.table.debit') }}</th>
                            <th style="padding:.5rem;text-align:end;">{{ __('admin.opening_balances.table.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr style="border-bottom:1px solid #f3f4f6;{{ $row['error'] ? 'background:#fef2f2;' : '' }}">
                                <td style="padding:.5rem;">
                                    <span style="font-family:monospace;">{{ $row['code'] }}</span>
                                    @if ($row['name']) — {{ $row['name'] }} @endif
                                    @if ($row['error'])
                                        <div style="color:#b91c1c;font-size:.75rem;">{{ $row['error'] }}</div>
                                    @endif
                                </td>
                                <td style="padding:.5rem;text-align:end;">{{ number_format($row['debit'], 2) }}</td>
                                <td style="padding:.5rem;text-align:end;">{{ number_format($row['credit'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;border-top:2px solid #e5e7eb;">
                            <td style="padding:.5rem;">
                                {{ $preview['balanced']
                                    ? __('admin.opening_balances.table.balanced')
                                    : __('admin.opening_balances.table.not_balanced') }}
                            </td>
                            <td style="padding:.5rem;text-align:end;">{{ number_format($preview['debit'], 2) }}</td>
                            <td style="padding:.5rem;text-align:end;">{{ number_format($preview['credit'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
