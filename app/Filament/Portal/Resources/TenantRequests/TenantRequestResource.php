<?php

namespace App\Filament\Portal\Resources\TenantRequests;

use App\Filament\Portal\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Portal\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Filament\Portal\Resources\TenantRequests\Pages\ViewTenantRequest;
use App\Filament\Portal\Resources\TenantRequests\Schemas\TenantRequestForm;
use App\Filament\Portal\Resources\TenantRequests\Schemas\TenantRequestInfolist;
use App\Filament\Portal\Resources\TenantRequests\Tables\TenantRequestsTable;
use App\Models\TenantRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TenantRequestResource extends Resource
{
    protected static ?string $slug = 'requests';

    protected static ?string $model = TenantRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.maintenance');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant_request.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return TenantRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TenantRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Portal\RelationManagers\PortalTenantRequestCommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantRequests::route('/'),
            'create' => CreateTenantRequest::route('/create'),
            'view' => ViewTenantRequest::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \App\Support\Portal::tenantId());
    }

    public static function canCreate(): bool
    {
        // Only the tenant-admin may submit requests; other users are read-only.
        return \App\Support\Portal::isAdmin();
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
