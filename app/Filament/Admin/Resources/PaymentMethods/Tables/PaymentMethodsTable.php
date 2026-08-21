<?php

namespace App\Filament\Admin\Resources\PaymentMethods\Tables;

use App\Models\PaymentMethod;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (PaymentMethod $record): string => $record->label()),

                TextColumn::make('ledgerAccount.name')
                    ->label(__('admin.fields.ledger_account'))
                    // The FLOOR is what an operator most needs to see, because null is the normal
                    // state and a blank cell would read as "posts nowhere". This says where the
                    // rail's money actually lands today, and marks it as the default rather than a
                    // choice somebody made.
                    ->state(fn (PaymentMethod $record): string => $record->ledgerAccount?->name
                        ?? __('admin.payment_methods.floor', [
                            'role' => $record->code === 'cash'
                                ? __('admin.posting_roles.cash')
                                : __('admin.posting_roles.bank'),
                        ]))
                    ->badge()
                    ->color(fn (PaymentMethod $record): string => $record->ledger_account_id !== null ? 'success' : 'gray'),

                IconColumn::make('for_inbound')->label(__('admin.fields.for_inbound'))->boolean(),
                IconColumn::make('for_outbound')->label(__('admin.fields.for_outbound'))->boolean(),

                TextColumn::make('settlement_days')
                    ->label(__('admin.fields.settlement_days'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                TernaryFilter::make('for_inbound')->label(__('admin.fields.for_inbound')),
                TernaryFilter::make('for_outbound')->label(__('admin.fields.for_outbound')),
            ])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
