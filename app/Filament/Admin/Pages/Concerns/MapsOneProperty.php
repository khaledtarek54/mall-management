<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\Asset;
use App\Support\AssignedAssets;
use App\Support\Filament\EntitySelect;
use App\Support\ReportPreferences;
use App\Support\TenantScope;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * A page that draws ONE property's floor plan — the property resolution both maps need.
 *
 * Extracted when the rentable-item map arrived (2026-08-26). Everything here was written once for
 * `OccupancyMap` and is about the PAGE rather than about units: which properties this operator may
 * map, which one is being mapped, how a tampered `?asset=` is clamped, and the picker that is only
 * meaningful when no single property is the active tenant. A second copy of it is how the two maps
 * would come to disagree about who may see which mall — the one class of drift this codebase has
 * paid for repeatedly.
 *
 * **Why the picker is hand-rolled rather than `ReportFilters::property()`.** The shared control is
 * an OPTIONAL narrowing whose empty value means "all properties"; a map is drawn FOR one property
 * and an empty value has no rendering. Both pages are therefore registered in
 * `ReportFilters::EXEMPT` with that reason — and registering the trait itself is not enough,
 * because the gate sweeps `app/Filament/Admin/Pages` file by file.
 *
 * The host page must:
 *   - declare `public ?int $assetId = null;`
 *   - call `mountPropertyMap()` from its own `mount()`
 *   - scope its table query with `resolvedAssetId()`, and fall CLOSED when it is null
 */
trait MapsOneProperty
{
    /**
     * Resolve the property to map, honouring a link and refusing a tampered one.
     *
     * A single active tenant wins outright: the panel is property-first, so the picker is only
     * meaningful in the All-Properties mode that operational screens no longer reach.
     */
    protected function mountPropertyMap(): void
    {
        if (($tenantAssetId = TenantScope::currentAssetId()) !== null) {
            $this->assetId = $tenantAssetId;

            return;
        }

        $requested = (int) request()->query('asset', 0);

        // Never default to (or honour) a property the user isn't assigned to.
        $this->assetId = $this->isAssetVisible($requested)
            ? $requested
            : $this->visibleAssets()->value('id');

        ReportPreferences::restore($this);
    }

    public function isAllPropertiesMode(): bool
    {
        return TenantScope::currentAssetId() === null;
    }

    /**
     * Properties the current user may map — their assigned set, or every real property for
     * super_admin / unassigned (the single-mall back-compat). The synthetic "All Properties"
     * pseudo-asset is always excluded.
     *
     * @return Builder<Asset>
     */
    protected function visibleAssets(): Builder
    {
        $allowedIds = AssignedAssets::idsForCurrentUser();

        return Asset::query()
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))
            ->orderBy('name');
    }

    protected function isAssetVisible(?int $assetId): bool
    {
        return $assetId
            ? (clone $this->visibleAssets())->whereKey($assetId)->exists()
            : false;
    }

    /**
     * The property actually being mapped — the selection CLAMPED to the visible set, falling back
     * to the first one the user may see. A tampered `?asset=` or Livewire value for an unassigned
     * property never resolves.
     */
    public function resolvedAssetId(): ?int
    {
        return $this->isAssetVisible($this->assetId)
            ? $this->assetId
            : $this->visibleAssets()->value('id');
    }

    /** The property picker — only meaningful when no single property is the active tenant. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->visible(fn (): bool => $this->isAllPropertiesMode() && $this->visibleAssets()->count() > 1)
                    ->schema([
                        EntitySelect::make('assetId')
                            ->label(__('admin.occupancy.select_property'))
                            ->entity(Asset::class)
                            ->modifyOptionsQuery(fn ($query) => $query->whereIn('id', $this->visibleAssets()->pluck('id')))
                            ->live()
                            // Remembering happens HERE rather than through ReportFilters, because
                            // this picker is exempt from the shared component (see
                            // ReportFilters::EXEMPT) — the exemption is about the CONTROL, not
                            // about whether the choice is worth keeping. Wired at the only other
                            // place it can be.
                            ->afterStateUpdated(fn ($livewire) => ReportPreferences::remember($livewire)),
                    ]),
            ]);
    }
}
