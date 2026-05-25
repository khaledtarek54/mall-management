<?php

namespace App\Filament\Admin\Resources\CamExpensePools;

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\ListCamExpensePools;
use App\Filament\Admin\Resources\CamExpensePools\Schemas\CamExpensePoolForm;
use App\Filament\Admin\Resources\CamExpensePools\Tables\CamExpensePoolsTable;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\CamExpensePool;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CamExpensePoolResource extends Resource
{
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'cam';
    }

    protected static ?string $model = CamExpensePool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 7;

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

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
        return __('admin.groups.operations');
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

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function scopeEloquentQueryToTenant(Builder $query, ?\Illuminate\Database\Eloquent\Model $tenant): Builder
    {
        if ($tenant instanceof \App\Models\Asset && $tenant->isAllProperties()) {
            return $query;
        }

        return parent::scopeEloquentQueryToTenant($query, $tenant);
    }
}
