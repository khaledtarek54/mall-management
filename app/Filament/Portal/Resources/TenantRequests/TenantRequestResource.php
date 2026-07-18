<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests;

use App\Filament\Portal\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Filament\Portal\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Portal\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Portal\Resources\MaintenanceRequests\Schemas\MaintenanceRequestForm;
use App\Filament\Portal\Resources\MaintenanceRequests\Schemas\MaintenanceRequestInfolist;
use App\Filament\Portal\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Models\TenantRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaintenanceRequestResource extends Resource
{
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
        return __('admin.resources.maintenance_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.maintenance_request.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenanceRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Portal\RelationManagers\PortalMaintenanceCommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceRequests::route('/'),
            'create' => CreateMaintenanceRequest::route('/create'),
            'view' => ViewMaintenanceRequest::route('/{record}'),
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
