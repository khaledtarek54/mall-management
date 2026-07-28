{{--
    Shared shell for the report pages (trial balance, general ledger, the three
    financial statements, AR ageing, occupancy map, monthly close). Filament
    requires a view per Page; this is that and nothing more — the filter strip,
    the stats and the report itself are declared in PHP as a Schema, widgets and
    a Table, so there is no presentation markup here to drift out of the design
    system or miss dark mode.
--}}
<x-filament-panels::page>
    {{ $this->filtersForm }}

    <x-filament-widgets::widgets
        :columns="$this->getHeaderWidgetsColumns()"
        :data="$this->getWidgetData()"
        :widgets="$this->getVisibleHeaderWidgets()"
    />

    {{ $this->table }}
</x-filament-panels::page>
