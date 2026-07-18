<?php

namespace App\Filament\Owner\Resources\MaintenanceRequests;

use App\Filament\Owner\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Owner\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Owner\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Models\TenantRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaintenanceRequestResource extends Resource
{
    protected static ?string $model = TenantRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 3;

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

    public static function table(Table $table): Table
    {
        return MaintenanceRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceRequests::route('/'),
            'view' => ViewMaintenanceRequest::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = Auth::id();

        return parent::getEloquentQuery()
            ->with(['tenant', 'unit.asset', 'assignee'])
            ->whereHas('unit.asset.owners', fn ($q) => $q->where('user_id', $userId));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * Open maintenance request count across the owner's portfolio. See
     * audit M10 F-40.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $hasUrgent = static::getEloquentQuery()
            ->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->exists();

        return $hasUrgent ? 'danger' : 'warning';
    }
}
