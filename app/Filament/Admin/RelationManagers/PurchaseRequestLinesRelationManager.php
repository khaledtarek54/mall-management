<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Support\Modules;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The lines of a procurement request (FR-PROC-01: "item(s), quantity, and justification").
 *
 * A line is either a catalog item — which becomes stock on receipt (FR-PROC-04) — or free text for
 * a service/non-catalog thing, which does not. The model enforces the either/or; this offers both.
 */
class PurchaseRequestLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.procurement.fields.lines');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('procurement')
            && (auth()->user()?->can('procurement.view') ?? false);
    }

    private function request(): PurchaseRequest
    {
        return $this->getOwnerRecord();
    }

    /**
     * Lines are only editable while the request is still a request. Once someone has approved a
     * VALUE, changing what is being bought behind that approval is the whole failure FR-PROC-02
     * exists to prevent.
     */
    private function editable(): bool
    {
        return $this->request()->status === PurchaseRequest::STATUS_REQUESTED
            && (auth()->user()?->can('procurement.edit') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('inventory_item_id')
                ->label(__('admin.procurement.fields.item'))
                ->helperText(__('admin.procurement.fields.item_hint'))
                // The catalog is a deliberately SHARED register — a pump seal is the same part in
                // every mall — so it is not property-filtered.
                ->options(fn () => InventoryItem::query()->where('is_active', true)->orderBy('sku')
                    ->get(['id', 'sku', 'name'])
                    ->mapWithKeys(fn (InventoryItem $i) => [$i->id => $i->sku.' — '.$i->name])->all())
                // Options exclude deactivated items, but a line may reference an item deactivated
                // after it was added — resolve any stored item so edit shows its SKU, not the raw id.
                ->getOptionLabelUsing(fn ($value): ?string => ($i = InventoryItem::find($value)) ? $i->sku.' — '.$i->name : null)
                ->searchable()->preload()->native(false)->live()
                ->requiredWithout('description'),

            TextInput::make('description')
                ->label(__('admin.procurement.fields.description'))
                ->maxLength(255)
                ->live()
                ->requiredWithout('inventory_item_id')
                // The model refuses both; saying so here beats a 500 on save.
                ->disabled(fn (callable $get) => filled($get('inventory_item_id'))),

            TextInput::make('quantity')
                ->label(__('admin.procurement.fields.quantity'))
                ->numeric()->minValue(0.001)->required(),

            TextInput::make('unit_cost')
                ->label(__('admin.procurement.fields.unit_cost'))
                ->prefix('EGP')
                ->numeric()
                // A catalog line becomes stock on receipt, and stock that arrives at zero value
                // posts nothing to the GL — so it needs a real cost, refused here rather than at
                // receipt (after the mall has already committed to buy it). A service never
                // becomes stock, so it may legitimately be free.
                ->minValue(fn (callable $get) => filled($get('inventory_item_id')) ? 0.01 : 0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('item'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin.procurement.fields.item'))
                    ->state(fn (PurchaseRequestLine $record) => $record->label()),
                TextColumn::make('quantity')->label(__('admin.procurement.fields.quantity')),
                TextColumn::make('unit_cost')->label(__('admin.procurement.fields.unit_cost'))->money('EGP')->alignRight(),
                TextColumn::make('line_value')->label(__('admin.procurement.fields.line_value'))->money('EGP')->alignRight(),
                TextColumn::make('stock_movement_id')
                    ->label(__('admin.procurement.fields.stocked'))
                    ->badge()
                    ->state(fn (PurchaseRequestLine $record) => match (true) {
                        ! $record->isStockable() => __('admin.procurement.fields.not_stock'),
                        $record->stock_movement_id !== null => __('admin.procurement.fields.stocked_yes'),
                        default => __('admin.procurement.fields.stocked_no'),
                    })
                    ->color(fn (PurchaseRequestLine $record) => $record->stock_movement_id !== null ? 'success' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => $this->editable())
                    ->authorize(fn () => $this->editable()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => $this->editable())
                    ->authorize(fn () => $this->editable()),
                DeleteAction::make()
                    ->visible(fn () => $this->editable())
                    ->authorize(fn () => $this->editable()),
            ])
            ->defaultSort('id');
    }
}
