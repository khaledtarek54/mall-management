<?php

namespace App\Filament\Portal\Resources\CamAllocations;

use App\Filament\Portal\Resources\CamAllocations\Pages\ListCamAllocations;
use App\Filament\Portal\Resources\CamAllocations\Pages\ViewCamAllocation;
use App\Filament\Portal\Resources\CamAllocations\Schemas\CamAllocationInfolist;
use App\Filament\Portal\Resources\CamAllocations\Tables\CamAllocationsTable;
use App\Models\CamAllocation;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CamAllocationResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    protected static ?string $model = CamAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 5;

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
        return parent::getEloquentQuery()
            // `lease.tenant` / `unitOwnership.owner` are here for the statement button's
            // `->recipient()`, which resolves the party the PDF is addressed to and takes its
            // download language from that party's stored `locale`.
            ->with(['pool.asset', 'lease.tenant', 'lease.unit.asset', 'unitOwnership.owner', 'unitOwnership.unit'])
            // The signed-in party's own share, whichever kind of agreement carries it. Scoped
            // through `lease` alone this returned NOTHING for a unit owner — his allocation has no
            // lease (he is a participant in his own right since phase 3) — so he was billed a CAM
            // true-up he could not see the basis of.
            ->where(function (Builder $q) {
                $tenantId = Portal::tenantId();

                $q->whereHas('lease', fn (Builder $l) => $l->where('tenant_id', $tenantId))
                    ->orWhereHas('unitOwnership', fn (Builder $o) => $o->where('tenant_id', $tenantId));
            });
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
