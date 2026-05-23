<?php

namespace App\Filament\Owner\Resources\Invoices\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.asset.name')
                    ->label(__('admin.tables.asset.name'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY'))
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP', divideBy: 1)
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP', divideBy: 1)
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'info',
                        'issued' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.invoice')),
            ])
            ->defaultSort('issue_date', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
