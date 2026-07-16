<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Tables;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\MaintenanceWorkOrder;
use App\Models\VendorBill;
use App\Services\ApplySlaPenaltyService;
use App\Services\AssessSlaPenaltyService;
use App\Services\MaintenanceWorkOrderService;
use App\Services\RaiseCorrectiveMaintenanceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MaintenanceWorkOrdersTable
{
    private static function canComplete(): bool
    {
        return auth()->user()?->can('preventive_maintenance.complete') ?? false;
    }

    private static function canCreate(): bool
    {
        return auth()->user()?->can('preventive_maintenance.create') ?? false;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit', 'equipment', 'parentWorkOrder', 'sourceItem', 'penalty']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.preventive_maintenance.fields.reference'))
                    ->fontFamily('mono')
                    // FR-CM-15 — the chain is visible from the list, not buried on the edit
                    // page: "why does this job exist?" is the first question about a CM.
                    ->description(fn (MaintenanceWorkOrder $record) => $record->parentWorkOrder
                        ? __('admin.preventive_maintenance.cm.follow_up_of').' '.$record->parentWorkOrder->reference
                        : ($record->sourceItem ? __('admin.preventive_maintenance.cm.from_check').': '.$record->sourceItem->label : null))
                    ->searchable(),
                TextColumn::make('work_order_type')
                    ->label(__('admin.preventive_maintenance.fields.work_order_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.work_order_types.{$state}"))
                    ->color(fn (string $state) => $state === MaintenanceWorkOrder::TYPE_CM ? 'warning' : 'gray')
                    ->description(fn (MaintenanceWorkOrder $record) => $record->execution_type
                        ? __("admin.preventive_maintenance.execution_types.{$record->execution_type}")
                        : null),
                TextColumn::make('title')
                    ->label(__('admin.preventive_maintenance.fields.title'))
                    ->weight('bold')
                    ->description(fn (MaintenanceWorkOrder $record) => __("admin.preventive_maintenance.categories.{$record->category}"))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.preventive_maintenance.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('equipment.code')
                    ->label(__('admin.preventive_maintenance.equipment.singular'))
                    ->fontFamily('mono')
                    ->description(fn (MaintenanceWorkOrder $record) => $record->equipment?->name_en)
                    ->placeholder('—')
                    ->toggleable(),
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
                TextColumn::make('priority')
                    ->label(__('admin.preventive_maintenance.fields.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.priorities.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    })
                    ->toggleable(),
                TextColumn::make('target_resolution_at')
                    ->label(__('admin.preventive_maintenance.sla.target'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    // Red once the deadline has passed and the job is still open — the
                    // whole point of the clock is that it is visible before someone asks.
                    ->color(fn (MaintenanceWorkOrder $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (MaintenanceWorkOrder $record) => $record->isOverdue()
                        ? __('admin.preventive_maintenance.sla.overdue').' · '.$record->hoursOverSla().'h'
                        : null)
                    ->toggleable(),
                TextColumn::make('penalty.amount')
                    ->label(__('admin.preventive_maintenance.penalty.label'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->description(fn (MaintenanceWorkOrder $record) => $record->penalty
                        ? __("admin.preventive_maintenance.penalty.statuses.{$record->penalty->status}")
                        : null)
                    // Grey once waived: the number stays visible for audit, but it is not owed.
                    ->color(fn (MaintenanceWorkOrder $record) => match ($record->penalty?->status) {
                        'final' => 'danger',
                        'pending' => 'warning',
                        'waived' => 'gray',
                        default => null,
                    })
                    ->toggleable(),
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
            ->filters([
                // Preventive and corrective share a list; an engineer looking for faults
                // should not have to read past every scheduled visit to find them.
                SelectFilter::make('work_order_type')
                    ->label(__('admin.preventive_maintenance.fields.work_order_type'))
                    ->options(fn () => __('admin.preventive_maintenance.work_order_types')),
                SelectFilter::make('execution_type')
                    ->label(__('admin.preventive_maintenance.fields.execution_type'))
                    ->options(fn () => __('admin.preventive_maintenance.execution_types')),
                SelectFilter::make('status')
                    ->label(__('admin.preventive_maintenance.fields.status'))
                    ->options(fn () => __('admin.preventive_maintenance.statuses')),
                SelectFilter::make('priority')
                    ->label(__('admin.preventive_maintenance.fields.priority'))
                    ->options(fn () => __('admin.preventive_maintenance.priorities')),
                Filter::make('sla_breached')
                    ->label(__('admin.preventive_maintenance.sla.breached_filter'))
                    ->query(fn ($query) => $query
                        ->whereNotNull('target_resolution_at')
                        ->where('target_resolution_at', '<', now())
                        ->whereNotIn('status', MaintenanceWorkOrder::TERMINAL)),
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
                // FR-CM-14/15 — the external company closed it but the work isn't done.
                // Deliberately available ON A TERMINAL order: that is the point. The client
                // wants a NEW linked job rather than reopening, so the original's SLA and
                // closure record survive for audit — which also keeps the project's
                // terminal-immutability rule intact instead of bending it.
                Action::make('follow_up')
                    ->label(__('admin.preventive_maintenance.cm.follow_up'))
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->color('warning')
                    ->modalDescription(__('admin.preventive_maintenance.cm.follow_up_hint'))
                    ->visible(fn (MaintenanceWorkOrder $record) => $record->isTerminal() && self::canCreate())
                    ->authorize(fn () => self::canCreate())
                    ->schema(fn (MaintenanceWorkOrder $record) => CorrectiveWorkOrderForm::fields($record->asset_id))
                    ->action(function (MaintenanceWorkOrder $record, array $data): void {
                        abort_unless(self::canCreate(), 403);

                        $followUp = app(RaiseCorrectiveMaintenanceService::class)->asFollowUp($record, $data);

                        Notification::make()
                            ->title(__('admin.preventive_maintenance.cm.raised_notice'))
                            ->body($followUp->reference)
                            ->success()
                            ->send();
                    }),
                // FR-CM-08 (money) — deduct the penalty from what the vendor is owed.
                // Only offers bills that can actually absorb it: same vendor, postable, and
                // a balance at least the penalty, so the service's guards are a backstop
                // rather than the way the user finds out.
                Action::make('charge_penalty')
                    ->label(__('admin.preventive_maintenance.penalty.charge'))
                    ->icon('heroicon-o-banknotes')
                    ->color('danger')
                    ->modalDescription(__('admin.preventive_maintenance.penalty.charge_hint'))
                    ->visible(fn (MaintenanceWorkOrder $record) => $record->penalty?->isChargeable() === true
                        && MaintenanceWorkOrderResource::canEdit($record))
                    ->authorize(fn (MaintenanceWorkOrder $record) => MaintenanceWorkOrderResource::canEdit($record))
                    ->schema(fn (MaintenanceWorkOrder $record) => [
                        Select::make('vendor_bill_id')
                            ->label(__('admin.preventive_maintenance.penalty.bill'))
                            ->options(fn () => VendorBill::query()
                                ->where('vendor_id', $record->vendor_id)
                                ->whereNotIn('status', ['draft', 'cancelled'])
                                ->where('balance', '>=', $record->penalty?->amount ?? 0)
                                ->orderByDesc('bill_date')
                                ->get()
                                ->mapWithKeys(fn (VendorBill $b) => [
                                    $b->id => $b->number.' — '.number_format((float) $b->balance, 2).' EGP',
                                ])
                                ->all())
                            ->placeholder(__('admin.preventive_maintenance.penalty.no_eligible_bill'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ])
                    ->action(function (MaintenanceWorkOrder $record, array $data): void {
                        abort_unless(MaintenanceWorkOrderResource::canEdit($record), 403);

                        $bill = VendorBill::find($data['vendor_bill_id']);

                        if ($record->penalty === null || $bill === null) {
                            return;
                        }

                        try {
                            app(ApplySlaPenaltyService::class)->toBill($record->penalty, $bill);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.penalty.charged_notice'))->success()->send();
                    }),
                // FR-CM-08 — an operator decides not to charge it (the vendor called ahead,
                // the part was on back-order, the breach was the mall's fault). Deliberately
                // available on a TERMINAL order: the penalty outlives the job, and the
                // decision is usually made after the fact.
                Action::make('waive_penalty')
                    ->label(__('admin.preventive_maintenance.penalty.waive'))
                    ->icon('heroicon-o-receipt-refund')
                    ->color('gray')
                    ->modalDescription(__('admin.preventive_maintenance.penalty.waive_hint'))
                    ->visible(fn (MaintenanceWorkOrder $record) => $record->penalty !== null
                        && ! $record->penalty->isWaived()
                        && MaintenanceWorkOrderResource::canEdit($record))
                    ->authorize(fn (MaintenanceWorkOrder $record) => MaintenanceWorkOrderResource::canEdit($record))
                    ->schema([
                        Textarea::make('waive_reason')
                            ->label(__('admin.preventive_maintenance.penalty.waive_reason'))
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (MaintenanceWorkOrder $record, array $data): void {
                        abort_unless(MaintenanceWorkOrderResource::canEdit($record), 403);

                        if ($record->penalty === null) {
                            return;
                        }

                        try {
                            app(AssessSlaPenaltyService::class)->waive($record->penalty, $data['waive_reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.penalty.waived_notice'))->success()->send();
                    }),
                EditAction::make()->visible(fn (MaintenanceWorkOrder $record) => ! $record->isTerminal() && MaintenanceWorkOrderResource::canEdit($record)),
            ])
            ->defaultSort('scheduled_for', 'desc');
    }
}
