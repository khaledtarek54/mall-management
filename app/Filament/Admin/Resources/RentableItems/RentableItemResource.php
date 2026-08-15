<?php

namespace App\Filament\Admin\Resources\RentableItems;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\RentableItems\Pages\CreateRentableItem;
use App\Filament\Admin\Resources\RentableItems\Pages\EditRentableItem;
use App\Filament\Admin\Resources\RentableItems\Pages\ListRentableItems;
use App\Filament\Admin\Resources\RentableItems\Pages\ViewRentableItem;
use App\Filament\Admin\Resources\RentableItems\Schemas\RentableItemForm;
use App\Filament\Admin\Resources\RentableItems\Tables\RentableItemsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\RentableItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Parking bays, storage and signage — let alongside a lease, but never lettable AREA.
 *
 * Lives under Leasing rather than Operations: an operator reaches this screen while doing a deal,
 * not while doing maintenance. See docs/benchmarks/yardi/09-yardi-space-and-parking.md for why this
 * is its own register and not a unit category.
 */
class RentableItemResource extends Resource
{
    // asset_id is client-supplied (the operator picks the mall), so Filament's ownership hook is
    // off and reads are scoped below — the Announcements tenancy trap.
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = RentableItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'code';

    protected static function permissionModule(): string
    {
        return 'rentable_items';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.rentable_item.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.rentable_item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.rentable_item.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return RentableItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RentableItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRentableItems::route('/'),
            'create' => CreateRentableItem::route('/create'),
            'view' => ViewRentableItem::route('/{record}'),
            'edit' => EditRentableItem::route('/{record}/edit'),
        ];
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['search_text'];
    }
}
