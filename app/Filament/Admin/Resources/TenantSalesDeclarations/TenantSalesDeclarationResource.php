<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\EditTenantSalesDeclaration;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Schemas\TenantSalesDeclarationForm;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Tables\TenantSalesDeclarationsTable;
use App\Models\TenantSalesDeclaration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantSalesDeclarationResource extends Resource
{
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'tenant_sales';
    }

    protected static ?string $model = TenantSalesDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tenant_sales');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant_sales.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant_sales.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = TenantSalesDeclaration::where('status', 'submitted')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return TenantSalesDeclarationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantSalesDeclarationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantSalesDeclarations::route('/'),
            'create' => CreateTenantSalesDeclaration::route('/create'),
            'edit' => EditTenantSalesDeclaration::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $ids = \App\Support\AssignedAssets::idsForCurrentUser();
        if ($ids !== null) {
            $query->whereHas('lease.unit', fn ($q) => $q->whereIn('asset_id', $ids));
        }

        return $query;
    }
}
