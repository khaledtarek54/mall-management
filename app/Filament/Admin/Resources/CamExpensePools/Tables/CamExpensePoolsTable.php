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
    /**
     * The predicate for each write action, named ONCE so visible() (the UI) and action() (the real
     * gate) can't drift. `mountAction()` never checks isVisible(), so a hidden action is still
     * dispatchable by a crafted Livewire call — every write must re-assert in action() (abort_unless).
     * Both the permission AND the status must be re-checked: `viewer`/`owner` hold cam.view (so the
     * pool list renders) but not the action perms, and re-opening a reconciled pool must stay blocked.
     */
    public static function canGenerate(CamExpensePool $record): bool
    {
        return in_array($record->status, ['draft', 'reconciling'], true)
            && (auth()->user()?->can('cam.generate_allocations') ?? false);
    }

    public static function canMarkReconciled(CamExpensePool $record): bool
    {
        return $record->status === 'reconciling'
            && (auth()->user()?->can('cam.mark_reconciled') ?? false);
    }

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
                    ->visible(fn (CamExpensePool $record) => self::canGenerate($record))
                    ->action(function (CamExpensePool $record): void {
                        abort_unless(self::canGenerate($record), 403);
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
                    ->visible(fn (CamExpensePool $record) => self::canMarkReconciled($record))
                    ->action(function (CamExpensePool $record): void {
                        abort_unless(self::canMarkReconciled($record), 403);
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
            ])
            ->emptyStateIcon('heroicon-o-building-library')
            ->emptyStateHeading(__('admin.empty.cam.heading'))
            ->emptyStateDescription(__('admin.empty.cam.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.cam.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
