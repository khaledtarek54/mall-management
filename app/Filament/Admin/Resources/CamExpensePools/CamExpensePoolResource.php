<?php

namespace App\Filament\Admin\Resources\CamExpensePools;

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\ListCamExpensePools;
use App\Filament\Admin\Resources\CamExpensePools\Schemas\CamExpensePoolForm;
use App\Filament\Admin\Resources\CamExpensePools\Tables\CamExpensePoolsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\CamExpensePool;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CamExpensePoolResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'cam';
    }

    protected static ?string $model = CamExpensePool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 6;

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
        return __('admin.navigation.cam');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.cam_pool.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.cam_pool.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function form(Schema $schema): Schema
    {
        return CamExpensePoolForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CamExpensePoolsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\CamAllocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCamExpensePools::route('/'),
            'create' => CreateCamExpensePool::route('/create'),
            'edit' => EditCamExpensePool::route('/{record}/edit'),
        ];
    }

}
