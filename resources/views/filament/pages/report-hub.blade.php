{{--
    The reports index. Filament requires a view per Page; this is that and nothing more — the
    catalogue itself is a Table declared in PHP, so there is no presentation markup here to drift
    out of the design system or miss dark mode. Same reasoning as ledger-report.blade.php.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
