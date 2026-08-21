<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\PaymentMethod;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.payments');
    }

    public function table(Table $table): Table
    {
        return $table
            // Property isolation: a payment is visible only if it settles an invoice in
            // one of the user's visible properties (a shared tenant may pay across malls).
            ->modifyQueryUsing(fn ($query) => $query->when(
                TenantScope::visibleAssetIds(),
                fn ($q, $ids) => $q->whereHas('invoices.lease.unit', fn ($u) => $u->whereIn('asset_id', $ids)),
            ))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.payment.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('payment_date')
                    ->label(__('admin.tables.payment.date'))
                    ->date('d/m/Y'),
                TextColumn::make('amount')
                    ->label(__('admin.tables.payment.amount'))
                    ->money('EGP')
                    ->color('success')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state))
                    ->color('info'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.payment.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'captured', 'reconciled', 'settled' => 'success',
                        'initiated', 'authorized' => 'warning',
                        'failed', 'bounced', 'refunded' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->options(fn () => PaymentMethod::filterOptionsFor('payments.method')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.payment')),
                Filter::make('payment_date_range')
                    ->label(__('admin.tables.payment.date'))
                    ->schema([
                        DatePicker::make('payment_from')
                            ->label(__('admin.filters.payment_from'))
                            ->native(false),
                        DatePicker::make('payment_until')
                            ->label(__('admin.filters.payment_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['payment_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))
                        ->when($data['payment_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('payment_date', 'desc')
            ->paginated([10, 25]);
    }
}
