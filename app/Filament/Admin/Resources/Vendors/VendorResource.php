<?php

namespace App\Filament\Admin\Resources\Vendors;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ContactsRelationManager;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Admin\Resources\Vendors\Tables\VendorsTable;
use App\Models\Vendor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VendorResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    // Vendors are typically cross-property service providers (one contractor
    // may serve several malls). Keep them global; scope by maintenance
    // assignment when needed via MaintenanceRequest's tenant scope.
    protected static bool $isScopedToTenant = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.vendors');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.vendor.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.vendor.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            ContractsRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['contracts as active_contracts_count' => fn ($q) => $q->where('status', 'active')]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'legal_name', 'tax_id', 'email', 'phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.vendor.type') => __("admin.enums.vendor_type.{$record->type}"),
            __('admin.tables.vendor.phone') => $record->phone,
            __('admin.tables.common.status') => __("admin.statuses.vendor.{$record->status}"),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Vendor contracts expiring in the next 30 days — landlord needs to renew or terminate.
        $count = \App\Models\VendorContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays(30))
            ->whereDate('end_date', '>=', now())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.tooltips.vendor_contracts_expiring');
    }
}
