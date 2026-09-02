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
    {{-- Optional, by the same `method_exists` idiom the stats and the unallocated notice use.
         A report whose filters belong on the TABLE — a query-backed register, where they narrow
         real SQL, are remembered by TableDefaults and render as clearable chips — declares no
         filter strip, and rendering one unconditionally was a 500 on the page rather than an
         empty section. --}}
    @if (method_exists($this, 'filtersForm'))
        {{ $this->filtersForm }}
    @endif

    @if (method_exists($this, 'statsWidgets'))
        {{ $this->statsWidgets }}
    @endif

    {{-- Money this statement is NOT showing, because it is filed against no property (EG-27).
         Opt-in by the same `method_exists` idiom the stats use, so the AR-ageing and occupancy
         pages that share this shell are unaffected. Absorbing these entries into a property's
         figures would show one operator-wide cost in full on every mall, so they are reported
         beside the statement instead of inside it. --}}
    @if (method_exists($this, 'unallocatedNotice') && ($unallocated = $this->unallocatedNotice()))
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="warning"
            :heading="\App\Support\UnallocatedNotice::heading()"
        >
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{-- Worded by the page, not here: an "as at" statement must not say "this period",
                     and the CSV copy has to read identically. --}}
                {{ $this->unallocatedBody($unallocated) }}
            </p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                <code>atriom:audit-property-dimension</code> — {{ __('admin.journal_entries.unallocated.remedy') }}
            </p>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
