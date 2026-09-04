<?php

namespace App\Filament\Admin\Resources\RentableItems\Tables;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RentableItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            // What `currentHolderLabel()` resolves against. Its own docblock states the contract:
            // it answers in PHP from the LOADED relations, so without this each row costs two
            // queries and the register becomes the N+1 the map was careful to avoid.
            ->modifyQueryUsing(fn ($query) => $query->with(['leases.tenant', 'ownerships.tenant']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.item_code'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.fields.item_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => __('admin.enums.rentable_item_type')[$state] ?? $state)
                    ->color('info'),
                TextColumn::make('name')
                    ->label(__('admin.fields.item_name'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('floor.code')
                    ->label(__('admin.pdf.floor'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => __('admin.enums.rentable_item_status')[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        RentableItem::STATUS_ASSIGNED => 'success',
                        RentableItem::STATUS_OUT_OF_SERVICE => 'danger',
                        default => 'warning',   // available — free, and therefore not yet earning
                    }),
                // Who holds it TODAY. A register that cannot answer "who has bay 42" is a list, not
                // a register — and it is the question an operator arrives with.
                // `currentHolderLabel()`, never the raw relation (SW-044). `leases` is the whole
                // morph history, unfiltered by status or by whether the holding is still open, so
                // this column listed the tenant who gave the bay back last year — and, because it
                // reads the LEASE relation only, it showed nothing at all for a bay held by a UNIT
                // OWNER, whose holding is an `ownerships` row.
                //
                // The model already answered this correctly and the register was not asking: the
                // method is documented as the reading half of `isSpokenFor()`, deliberately so that
                // the map cannot show a holder for a bay the register calls available. Two doors
                // onto one fact, allowed to disagree — the same shape as SW-076 and SW-165.
                TextColumn::make('current_holder')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->state(fn (RentableItem $record): ?string => $record->currentHolderLabel())
                    ->placeholder('—'),
                TextColumn::make('monthly_rate')
                    ->label(__('admin.fields.item_monthly_rate'))
                    ->money('EGP')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.item_type'))
                    ->options(fn () => __('admin.enums.rentable_item_type')),
                SelectFilter::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->options(fn () => __('admin.enums.rentable_item_status')),
                EntitySelectFilter::make('floor_id')
                    ->label(__('admin.pdf.floor'))
                    ->relationship('floor')
                    ->entity(Floor::class),
            ])
            // Clicking the row EDITS. Letting a bay is the reason an operator opens this screen, so
            // the read-only view would be a stop on the way to the thing they came to do. It stays
            // reachable from the row action, and it is where a viewer lands — `canEdit()` decides,
            // so a read-only role is never sent to a form it cannot submit.
            ->recordUrl(fn (RentableItem $record): string => RentableItemResource::canEdit($record)
                ? RentableItemResource::getUrl('edit', ['record' => $record])
                : RentableItemResource::getUrl('view', ['record' => $record]))
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(__('admin.empty.rentable_items.heading'))
            ->emptyStateDescription(__('admin.empty.rentable_items.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.rentable_items.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
