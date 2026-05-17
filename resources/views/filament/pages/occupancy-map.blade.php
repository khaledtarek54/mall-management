@php
    $editUnitRoute = fn ($id) => \App\Filament\Admin\Resources\Units\UnitResource::getUrl('edit', ['record' => $id]);
    $statusColors = [
        'occupied' => '#16a34a',
        'vacant' => '#dc2626',
        'reserved' => '#ca8a04',
        'maintenance' => '#6b7280',
    ];
@endphp

<x-filament-panels::page>
    @if ($assets->count() > 1)
        <x-filament::section :heading="__('admin.occupancy.select_property')" compact>
            <x-filament::input.wrapper>
                <x-filament::input.select
                    id="asset-selector"
                    wire:model.live="assetId"
                >
                    @foreach ($assets as $opt)
                        <option value="{{ $opt->id }}">{{ $opt->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">
            {{ $asset?->name ?? __('admin.widgets.occupancy_grid.no_asset') }}
        </x-slot>
        <x-slot name="description">
            {{ __('admin.widgets.occupancy_grid.description') }}
        </x-slot>

        @if (! $asset)
            <p style="color:rgb(107 114 128); font-size:0.875rem;">{{ __('admin.widgets.occupancy_grid.no_asset') }}</p>
        @else
            {{-- Legend / stats strip --}}
            <div style="display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem; font-size:0.875rem;">
                @foreach (['occupied','vacant','reserved','maintenance'] as $statusKey)
                    <div style="display:flex; align-items:center; gap:0.4rem;">
                        <span style="display:inline-block; width:0.75rem; height:0.75rem; border-radius:0.2rem; background:{{ $statusColors[$statusKey] }};"></span>
                        <span>{{ __("admin.statuses.unit.{$statusKey}") }} · <strong>{{ $stats[$statusKey] }}</strong></span>
                    </div>
                @endforeach
                <div style="margin-inline-start:auto;">
                    {{ __('admin.widgets.occupancy_grid.occupancy_rate') }}:
                    <strong>{{ $stats['total'] > 0 ? round($stats['occupied'] / $stats['total'] * 100) : 0 }}%</strong>
                    ({{ $stats['occupied'] }}/{{ $stats['total'] }})
                </div>
            </div>

            @foreach ($units->groupBy('floor') as $floor => $floorUnits)
                <div style="margin-bottom:1.25rem;">
                    <div style="font-weight:600; font-size:0.875rem; margin-bottom:0.5rem; color:rgb(107 114 128);">
                        {{ __('admin.pdf.floor') }}: {{ $floor ?: '—' }}
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:0.5rem;">
                        @foreach ($floorUnits as $unit)
                            @php
                                $bg = $statusColors[$unit->status] ?? '#9ca3af';
                                $tenant = $unit->activeLease?->tenant?->name;
                            @endphp
                            <a href="{{ $editUnitRoute($unit->id) }}"
                               title="{{ $unit->code }} · {{ __("admin.statuses.unit.{$unit->status}") }}{{ $tenant ? ' · ' . $tenant : '' }}"
                               style="display:flex; flex-direction:column; justify-content:space-between; padding:0.5rem; border-radius:0.4rem; background:{{ $bg }}; color:#ffffff; text-decoration:none; min-height:64px; box-shadow:0 1px 2px rgba(0,0,0,0.07);">
                                <div style="font-weight:700; font-size:0.875rem;">{{ $unit->code }}</div>
                                <div style="font-size:0.7rem; opacity:0.9; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    @if ($tenant)
                                        {{ $tenant }}
                                    @else
                                        {{ __("admin.statuses.unit.{$unit->status}") }}
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </x-filament::section>
</x-filament-panels::page>
