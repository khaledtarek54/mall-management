<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        @if (\App\Support\Portal::isAdmin())
            <div class="mt-6">
                <x-filament::button type="submit">
                    {{ __('admin.actions.save') }}
                </x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
