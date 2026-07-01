<?php

namespace App\Filament\Admin\Resources\VendorBills\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only listing of the payments settling this bill. Payments are recorded via
 * the "Record payment" header action (which is lock-safe and caps at the balance),
 * so this manager only displays them — no create/edit/delete here.
 */
class VendorBillPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.vendor_bill_payments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->weight('bold'),
                TextColumn::make('method')
                    ->label(__('admin.fields.method'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.vendor_bill_payment_method.{$state}") : '—')
                    ->color('gray'),
                TextColumn::make('payment_date')
                    ->label(__('admin.fields.payment_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('payment_date', 'desc');
    }
}
