<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Tables;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Models\MaintenanceWorkOrder;
use App\Services\MaintenanceWorkOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenanceWorkOrdersTable
{
    private static function canComplete(): bool
    {
        return auth()->user()?->can('preventive_maintenance.complete') ?? false;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.preventive_maintenance.fields.reference'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('admin.preventive_maintenance.fields.title'))
                    ->weight('bold')
                    ->description(fn (MaintenanceWorkOrder $record) => __("admin.preventive_maintenance.categories.{$record->category}"))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.preventive_maintenance.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('scheduled_for')
                    ->label(__('admin.preventive_maintenance.fields.scheduled_for'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state, MaintenanceWorkOrder $record) => ! $record->isTerminal() && $state && $state->isPast() ? 'danger' : null),
                TextColumn::make('progress')
                    ->label(__('admin.preventive_maintenance.fields.progress'))
                    ->state(fn (MaintenanceWorkOrder $record) => ($record->marked_items_count ?? 0).' / '.($record->items_count ?? 0))
                    ->badge()
                    // Amber once a check has failed — the visit is progressing but the
                    // order will need corrective follow-up. Green = all marked, none failed.
                    ->color(function (MaintenanceWorkOrder $record): string {
                        if (($record->failed_items_count ?? 0) > 0) {
                            return 'warning';
                        }

                        return ($record->items_count ?? 0) > 0 && ($record->marked_items_count ?? 0) >= $record->items_count
                            ? 'success'
                            : 'gray';
                    }),
                TextColumn::make('status')
                    ->label(__('admin.preventive_maintenance.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.statuses.$state"))
                    ->color(fn (string $state) => match ($state) {
                        'done' => 'success',
                        'in_progress' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(__('admin.preventive_maintenance.actions.start'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->visible(fn (MaintenanceWorkOrder $record) => $record->status === 'open' && self::canComplete())
                    ->authorize(fn () => self::canComplete())
                    // authorize() can't see the record, so re-check the permission AND
                    // the record's state server-side; the service owns the transition rules.
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless(self::canComplete(), 403);
                        app(MaintenanceWorkOrderService::class)->transition($record, 'in_progress');
                    }),
                Action::make('complete')
                    ->label(__('admin.preventive_maintenance.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && self::canComplete())
                    ->authorize(fn () => self::canComplete())
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless(self::canComplete(), 403);

                        try {
                            app(MaintenanceWorkOrderService::class)->transition($record, 'done');
                        } catch (\DomainException $e) {
                            // FR-PPM-07: unmarked checklist items block closure. A refusal
                            // is an expected outcome, not a fault — show it, don't 500.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.completed_notice'))->success()->send();
                    }),
                Action::make('cancel')
                    ->label(__('admin.preventive_maintenance.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && MaintenanceWorkOrderResource::canEdit($record))
                    ->authorize(fn (MaintenanceWorkOrder $record) => MaintenanceWorkOrderResource::canEdit($record))
                    ->action(function (MaintenanceWorkOrder $record): void {
                        abort_unless(MaintenanceWorkOrderResource::canEdit($record), 403);
                        app(MaintenanceWorkOrderService::class)->transition($record, 'cancelled');
                        Notification::make()->title(__('admin.preventive_maintenance.cancelled_notice'))->success()->send();
                    }),
                EditAction::make()->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && MaintenanceWorkOrderResource::canEdit($record)),
            ])
            ->defaultSort('scheduled_for', 'desc');
    }
}
