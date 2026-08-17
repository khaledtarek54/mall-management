<?php

namespace App\Filament\Admin\Resources\InventoryItems;

use App\Filament\Admin\RelationManagers\StockMovementsRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\InventoryItems\Pages\CreateInventoryItem;
use App\Filament\Admin\Resources\InventoryItems\Pages\EditInventoryItem;
use App\Filament\Admin\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Filament\Admin\Resources\InventoryItems\Schemas\InventoryItemForm;
use App\Filament\Admin\Resources\InventoryItems\Tables\InventoryItemsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The item catalog — shared (global) reference data, so it is NOT property-scoped.
 * On-hand quantity is DERIVED per row (SUM of movements) via withSum. Gated by the
 * `inventory` module + `inventory.*` permissions.
 */
class InventoryItemResource extends Resource
{
    // Global catalog — not property-scoped; opt out of Filament's asset tenancy.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'inventory';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.inventory.item.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.inventory.item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.inventory.item.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.inventory_assets');
    }

    public static function form(Schema $schema): Schema
    {
        return InventoryItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryItemsTable::configure($table);
    }

    /**
     * What actually moved. On-hand is DERIVED from these rows, so a stock figure the operator
     * doubts is only explicable by reading them — and they were reachable solely from the movements
     * register, filtered by hand.
     */
    public static function getRelations(): array
    {
        return [
            StockMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryItems::route('/'),
            'create' => CreateInventoryItem::route('/create'),
            'edit' => EditInventoryItem::route('/{record}/edit'),
        ];
    }

    /**
     * The properties this view is about — the selected one, or every one the user may see.
     * `null` means genuinely unrestricted (a portfolio role in All-Properties mode).
     *
     * @return array<int>|null
     */
    public static function scopedAssetIds(): ?array
    {
        return TenantScope::reportAssetIds(TenantScope::currentAssetId());
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived on-hand in one subquery — no per-row N+1.
        //
        // Scoped to the properties in view. `inventory_items` is a deliberately SHARED catalog
        // (a pump seal is the same part everywhere), so an unscoped sum answers the wrong
        // question: "how much exists anywhere", when FR-INV-01 asks to "track spare parts stock
        // levels **per mall/location**".
        //
        // It was unscoped, and it was a property leak as well as a wrong number — proven: a
        // manager restricted to mall A saw on_hand = 100 for an item mall A had NONE of, because
        // mall B held 100, and the reorder colour therefore painted it green ("well stocked") for
        // the one mall that was actually out. Any low-stock alert built on that figure would have
        // inherited the lie (FR-INV-03).
        $assetIds = static::scopedAssetIds();

        $scope = fn ($query) => $assetIds === null
            ? $query
            : $query->whereIn('warehouse_id', Warehouse::query()->whereIn('asset_id', $assetIds)->select('id'));

        return parent::getEloquentQuery()
            ->withSum(['movements as on_hand' => $scope], 'quantity')
            // What the stock on hand is WORTH, summed from the movements themselves — every
            // quantity at the cost it actually moved at. This is the SAME arithmetic the GL runs
            // (Dr Inventory on the way in, Cr on the way out), so the register, its total and the
            // Inventory account cannot disagree.
            //
            // It used to be `on_hand × unit_cost` — the CATALOGUE price — which answered a
            // different question the moment somebody updated a price: stock loaded at 100 and
            // re-priced to 300 showed 3,000 against a ledger holding 1,000, and the column's own
            // label calls it the figure an operator reconciles with. Removals carry a negative
            // quantity, so this follows the stock out of the door too (module 22 close-out).
            ->withSum(['movements as stock_value' => $scope], DB::raw('quantity * unit_cost'));
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
        ];
    }

    /**
     * The stock-on-hand register as CSV rows — what's held and what it's worth, the accountant's
     * working format. Reads the same property-scoped query the table shows (on_hand + the
     * ledger-tying stock value), so the export can never disagree with the screen OR the Inventory
     * account, and closes with a total-valuation row.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function stockRegisterCsv(): array
    {
        $rows = [];
        $totalValue = 0.0;

        /** @var InventoryItem $item */
        foreach (static::getEloquentQuery()->orderBy('name')->get() as $item) {
            $onHand = round((float) ($item->on_hand ?? 0), 3);
            // The ledger-tying value, not on_hand x catalogue price — see getEloquentQuery().
            $value = round((float) ($item->stock_value ?? 0), 2);
            $totalValue += $value;

            // The cost column is the AVERAGE the stock is carried at, derived so the row's own
            // arithmetic holds (on hand x cost = value). Printing the catalogue price beside a
            // ledger-tying value would put "10 x 300 = 1,000" in front of an accountant.
            $avgCost = $onHand != 0.0
                ? round($value / $onHand, 2)
                : round((float) $item->unit_cost, 2);

            $rows[] = [$item->sku, $item->name, $item->category ?? '', $item->unit, $onHand,
                $avgCost, $value];
        }

        $rows[] = ['', __('admin.reports.csv.total'), '', '', '', '', round($totalValue, 2)];

        return [
            'headers' => [
                __('admin.inventory.fields.sku'), __('admin.inventory.fields.name'),
                __('admin.inventory.fields.category'), __('admin.inventory.fields.unit'),
                __('admin.inventory.fields.on_hand'), __('admin.inventory.fields.avg_unit_cost'),
                __('admin.inventory.fields.value'),
            ],
            'rows' => $rows,
        ];
    }
}
