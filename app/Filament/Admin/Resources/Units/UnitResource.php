<?php

namespace App\Filament\Admin\Resources\Units;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Admin\Resources\Units\Schemas\UnitForm;
use App\Filament\Admin\Resources\Units\Tables\UnitsTable;
use App\Models\Unit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UnitResource extends Resource
{
    use BypassesScopingOnAll;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tenant_directory');
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
        // getEloquentQuery() respects the active Filament tenant (Asset) via
        // BypassesScopingOnAll, so the badge reflects the currently-viewed
        // property — not a global count. "All Properties" view returns the
        // portfolio-wide total because the trait skips scoping for the ALL
        // pseudo-asset.
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
