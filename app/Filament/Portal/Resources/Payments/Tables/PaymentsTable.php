<?php

namespace App\Filament\Portal\Resources\Payments\Tables;

use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.payment.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('payment_date')
                    ->label(__('admin.tables.payment.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.tables.payment.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.method.{$state}"))
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
                    ->options(fn () => collect(__('admin.enums.method'))->only(['card', 'bank_transfer', 'instapay', 'cash', 'cheque'])->all()),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.payment'))->only(['captured', 'reconciled', 'settled', 'failed', 'refunded'])->all()),
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
                        ->when($data['payment_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['payment_from'] ?? null) {
                            $indicators[] = __('admin.filters.payment_from').': '.Carbon::parse($data['payment_from'])->format('d/m/Y');
                        }
                        if ($data['payment_until'] ?? null) {
                            $indicators[] = __('admin.filters.payment_until').': '.Carbon::parse($data['payment_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('payment_date', 'desc');
    }
}
