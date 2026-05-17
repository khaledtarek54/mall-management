<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Admin\Resources\Tenants\Tables\TenantsTable;
use App\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tenants');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\TenantLeasesRelationManager::class,
            \App\Filament\Admin\RelationManagers\TenantPaymentsRelationManager::class,
            \App\Filament\Admin\RelationManagers\TenantMaintenanceRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'legal_name', 'email', 'phone', 'contact_person'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.tenant.phone') => $record->phone,
            __('admin.tables.tenant.email') => $record->email,
            __('admin.tables.common.status') => __("admin.statuses.tenant.{$record->status}"),
        ];
    }
}
