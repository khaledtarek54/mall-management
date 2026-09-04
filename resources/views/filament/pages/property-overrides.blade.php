<x-filament-panels::page>
    {{-- No submit button here, deliberately. The Save is a HEADER action, so ONE predicate —
         PropertyOverrides::canSave() — decides both whether it is offered and whether it runs. A
         button written in this Blade is gated by nothing: the page is reachable on `settings.view`
         while the write needs `settings.manage`, and three roles hold the first without the second.
         The form wrapper stays so Enter still submits, exactly as on /admin/settings. --}}
    <form wire:submit="save">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
