{{--
    Shared shell for the report pages (trial balance, general ledger, the three
    financial statements, AR ageing, occupancy map, monthly close). Filament
    requires a view per Page; this is that and nothing more — the filter strip,
    the stats and the report itself are declared in PHP as a Schema, widgets and
    a Table, so there is no presentation markup here to drift out of the design
    system or miss dark mode.

    Pages that show stat cards declare a `statsWidgets(Schema $schema): Schema`
    rather than `getHeaderWidgets()`: Filament's page component renders header
    widgets itself, so registering them there AND printing them here rendered
    every card twice.
--}}
<x-filament-panels::page>
    {{ $this->filtersForm }}

    @if (method_exists($this, 'statsWidgets'))
        {{ $this->statsWidgets }}
    @endif

    {{ $this->table }}
</x-filament-panels::page>
