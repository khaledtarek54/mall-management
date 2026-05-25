<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Filament\Admin\Resources\MaintenanceRequests\Pages\EditMaintenanceRequest;
use App\Filament\Admin\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Admin\Resources\MaintenanceRequests\Schemas\MaintenanceRequestForm;
use App\Filament\Admin\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Models\MaintenanceRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MaintenanceRequestResource extends Resource
{
    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'maintenance';
    }

    protected static ?string $model = MaintenanceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'reference';

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MaintenanceRequest::whereIn('status', MaintenanceRequest::OPEN_STATUSES)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $hasUrgent = MaintenanceRequest::whereIn('status', MaintenanceRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->exists();

        return $hasUrgent ? 'danger' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenanceRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\MaintenanceCommentsRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceRequests::route('/'),
            'create' => CreateMaintenanceRequest::route('/create'),
            'edit' => EditMaintenanceRequest::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($tenant = \Filament\Facades\Filament::getTenant()) {
            $query->whereHas('unit', fn ($q) => $q->where('asset_id', $tenant->getKey()));
        }

        return $query;
    }

    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'title', 'tenant.name', 'unit.code'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.lease.tenant') => $record->tenant?->name,
            __('admin.tables.lease.unit') => $record->unit?->code,
            __('admin.tables.common.status') => __("admin.statuses.maintenance_request.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant', 'unit']);
    }
}
