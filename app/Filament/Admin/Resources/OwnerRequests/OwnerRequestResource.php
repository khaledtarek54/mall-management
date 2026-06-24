<?php

namespace App\Filament\Admin\Resources\OwnerRequests;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Filament\Admin\Resources\OwnerRequests\Tables\OwnerRequestsTable;
use App\Models\OwnerRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OwnerRequestResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = OwnerRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static bool $isScopedToTenant = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.owner_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function table(Table $table): Table
    {
        return OwnerRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerRequests::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Operator inbox: only requests addressed to the operator team.
        return parent::getEloquentQuery()
            ->where('recipient', 'operator')
            ->with(['creator', 'asset']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->whereIn('status', OwnerRequest::OPEN_STATUSES)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }
}
