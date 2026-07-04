<?php

namespace App\Filament\Admin\Resources\FixedAssets;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Admin\Resources\FixedAssets\Schemas\FixedAssetForm;
use App\Filament\Admin\Resources\FixedAssets\Tables\FixedAssetsTable;
use App\Models\FixedAsset;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fixed-asset register (module 23), scoped to the current property (direct
 * asset_id, like Unit / Warehouse). Accumulated depreciation is DERIVED in one
 * subquery; net book value + the monthly charge are computed per row. Gated by
 * the `fixed_assets` module + `fixed_assets.*` permissions.
 */
class FixedAssetResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 43;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'fixed_assets';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.fixed_assets.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.fixed_assets.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.fixed_assets.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.fixed_assets.group');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FixedAssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\DepreciationEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
            'create' => CreateFixedAsset::route('/create'),
            'edit' => EditFixedAsset::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived accumulated depreciation in one subquery — no per-row N+1.
        return parent::getEloquentQuery()->withSum('depreciationEntries as accumulated', 'amount');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'tag'];
    }

    /**
     * Server-side guard against a tampered `asset_id` on create/edit. The form
     * Select scopes its options, but in "All Properties" mode the field is enabled
     * and its dehydrated value is client-supplied — so re-validate that the target
     * property is within the user's visible set (null = portfolio user, sees all).
     */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
