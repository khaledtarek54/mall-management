<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations;

use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\ViewTenantSalesDeclaration;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas\TenantSalesDeclarationForm;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas\TenantSalesDeclarationInfolist;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Tables\TenantSalesDeclarationsTable;
use App\Models\TenantSalesDeclaration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TenantSalesDeclarationResource extends Resource
{
    protected static ?string $model = TenantSalesDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 4;

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

    public static function form(Schema $schema): Schema
    {
        return TenantSalesDeclarationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TenantSalesDeclarationInfolist::configure($schema);
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
            'view' => ViewTenantSalesDeclaration::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('lease', fn ($q) => $q->where('tenant_id', \App\Support\Portal::tenantId()));
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
