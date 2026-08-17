<?php

namespace App\Filament\Admin\Resources\RentableItems\Tables;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Support\Filament\EntitySelectFilter;
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
                TextColumn::make('leases.tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->listWithLineBreaks()
                    ->limitList(1)
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
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
