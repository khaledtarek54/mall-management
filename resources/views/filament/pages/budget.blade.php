<x-filament-panels::page>
    @php($existing = $this->existing())

    {{-- Said BEFORE the button, not after: importing replaces the year, and unlike an opening
         balance there is no draft to review between the paste and the result. --}}
    @if ($existing['lines'] > 0)
        <div style="padding:.75rem 1rem;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;border-radius:.5rem;font-size:.875rem;">
            {{ __('admin.budget.existing_warning', [
                'year' => $existing['year'],
                'lines' => $existing['lines'],
                'total' => 'EGP '.number_format($existing['total'], 2),
            ]) }}
        </div>
    @endif

    <form wire:submit="import" class="mt-4">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('admin.budget.actions.import') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
