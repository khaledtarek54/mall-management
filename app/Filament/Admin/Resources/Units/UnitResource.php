<?php

namespace App\Filament\Admin\Resources\Units;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Admin\Resources\Units\Schemas\UnitForm;
use App\Filament\Admin\Resources\Units\Tables\UnitsTable;
use App\Models\Unit;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnitResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    /** Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note above). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still sees only their own malls.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.unit.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.unit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.unit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    /**
     * A unit's facility zone (`area_id`) must belong to the SAME property as the unit. The form
     * only scopes the picker's OPTIONS; that is not a server-side guarantee (a crafted Livewire
     * request can submit any id, and Filament's Select adds no implicit `exists`/`in` rule). Left
     * unguarded, a restricted user could tag a mall-A unit with a mall-B zone — which then leaks
     * mall-A request data to mall-B supervisors via the area-routing fan-out (module 30 → 11).
     * Mirrors AreaResource::assertSupervisorsInScope on the sibling relation.
     */
    public static function assertAreaInScope(mixed $areaId, ?int $assetId): void
    {
        if ($areaId === null || $areaId === '') {
            return;
        }

        abort_unless(
            \App\Models\Area::whereKey($areaId)->where('asset_id', $assetId)->exists(),
            403,
        );
    }

    public static function form(Schema $schema): Schema
    {
        return UnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnits::route('/'),
            'create' => CreateUnit::route('/create'),
            'edit' => EditUnit::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // getEloquentQuery() scopes to the active Filament tenant (Asset) itself
        // (see the trait note above), so the badge reflects the currently-viewed
        // property — not a global count. "All Properties" view returns the
        // portfolio-wide total (super_admin) or the user's visible set, because
        // getEloquentQuery() leaves the query unscoped / whereIn accordingly.
        return (string) static::getEloquentQuery()->where('status', 'vacant')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'asset.name', 'activeLease.tenant.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.resources.asset.singular') => $record->asset?->name,
            __('admin.tables.unit.category') => __("admin.enums.category.{$record->category}"),
            __('admin.tables.unit.tenant') => $record->activeLease?->tenant?->name,
            __('admin.tables.common.status') => __("admin.statuses.unit.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['asset', 'activeLease.tenant']);
    }
}
