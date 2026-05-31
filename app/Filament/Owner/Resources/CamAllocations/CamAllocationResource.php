<?php

namespace App\Filament\Owner\Resources\CamAllocations;

use App\Filament\Owner\Resources\CamAllocations\Pages\ListCamAllocations;
use App\Filament\Owner\Resources\CamAllocations\Pages\ViewCamAllocation;
use App\Filament\Owner\Resources\CamAllocations\Schemas\CamAllocationInfolist;
use App\Filament\Owner\Resources\CamAllocations\Tables\CamAllocationsTable;
use App\Models\CamAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CamAllocationResource extends Resource
{
    protected static ?string $model = CamAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.cam_allocations');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.cam_allocation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.cam_allocation.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.portfolio');
    }

    public static function table(Table $table): Table
    {
        return CamAllocationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CamAllocationInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCamAllocations::route('/'),
            'view' => ViewCamAllocation::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = Auth::id();

        return parent::getEloquentQuery()
            ->with(['pool.asset', 'lease.unit.asset', 'lease.tenant'])
            ->whereHas('pool.asset.owners', fn (Builder $q) => $q->where('user_id', $userId));
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
}
