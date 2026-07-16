<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\MaintenanceWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Services\MaintenanceWorkOrderService;
use App\Services\RaiseCorrectiveMaintenanceService;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The checklist on a preventive-maintenance work order (module 26). Engineers record
 * pass/fail per item (captured with who/when — FR-PPM-07); items can be added/removed
 * while the order is open. Marking is gated on `preventive_maintenance.complete`;
 * editing the list on `preventive_maintenance.edit`. A terminal (done/cancelled)
 * order's checklist is frozen.
 *
 * Was a ToggleColumn over an `is_done` boolean, which could not express a failed
 * inspection — the state a PPM visit exists to find.
 */
class MaintenanceChecklistRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.preventive_maintenance.checklist_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('preventive_maintenance') && (auth()->user()?->can('preventive_maintenance.view') ?? false);
    }

    private function orderEditable(): bool
    {
        return ! $this->getOwnerRecord()->isTerminal();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('markedBy'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin.preventive_maintenance.fields.label'))
                    ->wrap(),
                SelectColumn::make('result')
                    ->label(__('admin.preventive_maintenance.fields.result'))
                    ->options(fn () => collect(MaintenanceWorkOrderItem::RESULTS)
                        ->mapWithKeys(fn (string $r) => [$r => __("admin.preventive_maintenance.results.{$r}")])
                        ->all())
                    // `pending` stays selectable so a mis-click can be undone while the
                    // order is open; the completion gate is what enforces the outcome.
                    ->selectablePlaceholder(false)
                    // Markable only by a user who may complete work, on a non-terminal order.
                    ->disabled(fn () => ! $this->orderEditable() || ! (auth()->user()?->can('preventive_maintenance.complete') ?? false))
                    ->updateStateUsing(function (MaintenanceWorkOrderItem $record, string $state): void {
                        abort_unless((auth()->user()?->can('preventive_maintenance.complete') ?? false) && $this->orderEditable(), 403);
                        app(MaintenanceWorkOrderService::class)->markItem($record, $state);
                    }),
                TextColumn::make('markedBy.name')
                    ->label(__('admin.preventive_maintenance.fields.marked_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('add_item')
                    ->label(__('admin.preventive_maintenance.fields.label'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => $this->orderEditable() && (auth()->user()?->can('preventive_maintenance.edit') ?? false))
                    ->authorize(fn () => auth()->user()?->can('preventive_maintenance.edit') ?? false)
                    ->schema([
                        TextInput::make('label')->label(__('admin.preventive_maintenance.fields.label'))->required()->maxLength(255),
                    ])
                    // The service holds the order's row lock while inserting — a new item
                    // is born `pending`, so appending one to an order that is mid-complete
                    // must not slip past the FR-PPM-07 gate.
                    ->action(function (array $data): void {
                        abort_unless(auth()->user()?->can('preventive_maintenance.edit') ?? false, 403);
                        /** @var MaintenanceWorkOrder $order */
                        $order = $this->getOwnerRecord();

                        try {
                            app(MaintenanceWorkOrderService::class)->addItem($order, $data['label']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                // FR-CM-01 — a failed check is the canonical trigger for corrective work.
                // Shown only on a failed, not-yet-actioned item; the CM it raises is a
                // separate job, so the PPM visit still closes normally (a fail does not
                // block the gate).
                Action::make('raise_cm')
                    ->label(__('admin.preventive_maintenance.cm.raise'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->modalDescription(__('admin.preventive_maintenance.cm.raise_hint'))
                    ->visible(fn (MaintenanceWorkOrderItem $record) => $record->hasFailed()
                        && ! $record->correctiveWorkOrders()->exists()
                        && (auth()->user()?->can('preventive_maintenance.create') ?? false))
                    ->authorize(fn () => auth()->user()?->can('preventive_maintenance.create') ?? false)
                    ->schema(fn () => CorrectiveWorkOrderForm::fields($this->getOwnerRecord()->asset_id))
                    ->action(function (MaintenanceWorkOrderItem $record, array $data): void {
                        abort_unless(auth()->user()?->can('preventive_maintenance.create') ?? false, 403);

                        try {
                            $cm = app(RaiseCorrectiveMaintenanceService::class)->fromFailedCheck($record, $data);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.preventive_maintenance.cm.raised_notice'))
                            ->body($cm->reference)
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->visible(fn () => $this->orderEditable() && (auth()->user()?->can('preventive_maintenance.edit') ?? false))
                    ->using(function (MaintenanceWorkOrderItem $record): void {
                        abort_unless(auth()->user()?->can('preventive_maintenance.edit') ?? false, 403);
                        app(MaintenanceWorkOrderService::class)->removeItem($record);
                    }),
            ])
            ->defaultSort('id');
    }
}
