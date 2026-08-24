<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaseInvoicesRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.invoices');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($record) => $record->period_start?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—'),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('paid_amount')
                    ->label(__('admin.tables.invoice.paid'))
                    ->money('EGP')
                    ->color('success')
                    ->alignRight(),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'issued' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.invoice')),
                Filter::make('period')
                    ->label(__('admin.filters.period'))
                    ->schema([
                        DatePicker::make('period_from')
                            ->label(__('admin.filters.period_from'))
                            ->native(false),
                        DatePicker::make('period_until')
                            ->label(__('admin.filters.period_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['period_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '>=', $date))
                        ->when($data['period_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '<=', $date))),
                Filter::make('unpaid_only')
                    ->label(__('admin.filters.overdue_only'))
                    ->query(fn (Builder $query) => $query->where('balance', '>', 0)),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('issue_date', 'desc')
            ->paginated([10, 25]);
    }
}
