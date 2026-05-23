<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\CamAllocation;
use App\Services\CamReconciliationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CamAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tables.cam.allocations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($q) => $q->with(['lease.tenant', 'lease.unit']))
            ->columns([
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.cam.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.cam.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('pro_rata_share_pct')
                    ->label(__('admin.tables.cam.share'))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%'),
                TextColumn::make('allocated_amount')
                    ->label(__('admin.tables.cam.allocated'))
                    ->money('EGP', divideBy: 1),
                TextColumn::make('estimated_paid')
                    ->label(__('admin.tables.cam.estimated_paid'))
                    ->money('EGP', divideBy: 1)
                    ->toggleable(),
                TextColumn::make('true_up_amount')
                    ->label(__('admin.tables.cam.true_up'))
                    ->money('EGP', divideBy: 1)
                    ->weight('semibold')
                    ->color(fn ($state) => $state > 0 ? 'warning' : ($state < 0 ? 'success' : 'gray')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_allocation.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'billed' => 'success',
                        'disputed' => 'danger',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.cam_allocation')),
            ])
            ->defaultSort('allocated_amount', 'desc')
            ->recordActions([
                Action::make('bill')
                    ->label(__('admin.actions.bill_allocation'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.bill_allocation_confirm'))
                    ->visible(fn (CamAllocation $record) => $record->status === 'pending')
                    ->action(function (CamAllocation $record): void {
                        app(CamReconciliationService::class)->bill($record);
                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.allocation_billed'))
                            ->body(__('admin.notifications.allocation_billed_body', [
                                'amount' => number_format((float) $record->true_up_amount, 2),
                            ]))
                            ->send();
                    }),
            ]);
    }
}
