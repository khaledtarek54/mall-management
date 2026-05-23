<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Tables;

use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CamExpensePoolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('asset'))
            ->columns([
                TextColumn::make('asset.name')
                    ->label(__('admin.resources.asset.singular'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('period_year')
                    ->label(__('admin.fields.period_year'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('total_actual_expense')
                    ->label(__('admin.tables.cam.actual'))
                    ->money('EGP', divideBy: 1)
                    ->sortable(),
                TextColumn::make('total_estimated_collected')
                    ->label(__('admin.tables.cam.estimated'))
                    ->money('EGP', divideBy: 1),
                TextColumn::make('variance')
                    ->label(__('admin.tables.cam.variance'))
                    ->getStateUsing(fn (CamExpensePool $record) => $record->variance())
                    ->money('EGP', divideBy: 1)
                    ->color(fn ($state) => $state > 0 ? 'warning' : ($state < 0 ? 'success' : 'gray')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_pool.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'reconciling' => 'warning',
                        'reconciled' => 'success',
                        'closed' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('allocations_count')
                    ->label(__('admin.tables.cam.allocations'))
                    ->counts('allocations')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('reconciled_at')
                    ->label(__('admin.tables.cam.reconciled_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.cam_pool')),
            ])
            ->defaultSort('period_year', 'desc')
            ->recordActions([
                Action::make('generateAllocations')
                    ->label(__('admin.actions.generate_allocations'))
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.generate_allocations_confirm'))
                    ->visible(fn (CamExpensePool $record) => in_array($record->status, ['draft', 'reconciling']))
                    ->action(function (CamExpensePool $record): void {
                        $count = app(CamReconciliationService::class)->generateAllocations($record);
                        $record->update(['status' => 'reconciling']);
                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.allocations_generated'))
                            ->body(__('admin.notifications.allocations_generated_body', ['count' => $count]))
                            ->send();
                    }),
                Action::make('markReconciled')
                    ->label(__('admin.actions.mark_reconciled'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CamExpensePool $record) => $record->status === 'reconciling')
                    ->action(function (CamExpensePool $record): void {
                        $record->update([
                            'status' => 'reconciled',
                            'reconciled_at' => now(),
                            'reconciled_by_user_id' => auth()->id(),
                        ]);
                        Notification::make()->success()->title(__('admin.notifications.pool_reconciled'))->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
