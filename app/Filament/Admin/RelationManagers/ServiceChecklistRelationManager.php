<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\FacilityWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Services\FacilityWorkOrderService;
use App\Services\RaiseCorrectiveWorkOrderService;
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
 * while the order is open. Marking is gated on `facility.complete`;
 * editing the list on `facility.edit`. A terminal (done/cancelled)
 * order's checklist is frozen.
 *
 * Was a ToggleColumn over an `is_done` boolean, which could not express a failed
 * inspection — the state a PPM visit exists to find.
 */
class ServiceChecklistRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.facility.checklist_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('facility') && (auth()->user()?->can('facility.view') ?? false);
    }

    private function orderEditable(): bool
    {
        return ! $this->getOwnerRecord()->isTerminal();
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: FacilityWorkOrderItem carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('markedBy'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin.facility.fields.label'))
                    ->wrap(),
                SelectColumn::make('result')
                    ->label(__('admin.facility.fields.result'))
                    ->options(fn () => collect(FacilityWorkOrderItem::RESULTS)
                        ->mapWithKeys(fn (string $r) => [$r => __("admin.facility.results.{$r}")])
                        ->all())
                    // `pending` stays selectable so a mis-click can be undone while the
                    // order is open; the completion gate is what enforces the outcome.
                    ->selectablePlaceholder(false)
                    // Markable only by a user who may complete work, on a non-terminal order.
                    ->disabled(fn () => ! $this->orderEditable() || ! (auth()->user()?->can('facility.complete') ?? false))
                    ->updateStateUsing(function (FacilityWorkOrderItem $record, string $state): void {
                        abort_unless((auth()->user()?->can('facility.complete') ?? false) && $this->orderEditable(), 403);
                        app(FacilityWorkOrderService::class)->markItem($record, $state);
                    }),
                TextColumn::make('markedBy.name')
                    ->label(__('admin.facility.fields.marked_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('add_item')
                    ->label(__('admin.facility.fields.label'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => $this->orderEditable() && (auth()->user()?->can('facility.edit') ?? false))
                    ->authorize(fn () => auth()->user()?->can('facility.edit') ?? false)
                    ->schema([
                        TextInput::make('label')->label(__('admin.facility.fields.label'))->required()->maxLength(255),
                    ])
                    // The service holds the order's row lock while inserting — a new item
                    // is born `pending`, so appending one to an order that is mid-complete
                    // must not slip past the FR-PPM-07 gate.
                    ->action(function (array $data): void {
                        abort_unless(auth()->user()?->can('facility.edit') ?? false, 403);
                        /** @var FacilityWorkOrder $order */
                        $order = $this->getOwnerRecord();

                        try {
                            app(FacilityWorkOrderService::class)->addItem($order, $data['label']);
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
                    ->label(__('admin.facility.cm.raise'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->modalDescription(__('admin.facility.cm.raise_hint'))
                    ->visible(fn (FacilityWorkOrderItem $record) => $record->hasFailed()
                        && ! $record->correctiveWorkOrders()->exists()
                        && (auth()->user()?->can('facility.create') ?? false))
                    ->authorize(fn () => auth()->user()?->can('facility.create') ?? false)
                    ->schema(fn () => CorrectiveWorkOrderForm::fields($this->getOwnerRecord()->asset_id))
                    ->action(function (FacilityWorkOrderItem $record, array $data): void {
                        abort_unless(auth()->user()?->can('facility.create') ?? false, 403);

                        try {
                            $cm = app(RaiseCorrectiveWorkOrderService::class)->fromFailedCheck($record, $data);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.facility.cm.raised_notice'))
                            ->body($cm->reference)
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->visible(fn () => $this->orderEditable() && (auth()->user()?->can('facility.edit') ?? false))
                    ->authorize(fn () => $this->orderEditable() && (auth()->user()?->can('facility.edit') ?? false))
                    ->using(function (FacilityWorkOrderItem $record): void {
                        abort_unless(auth()->user()?->can('facility.edit') ?? false, 403);
                        app(FacilityWorkOrderService::class)->removeItem($record);
                    }),
            ])
            ->defaultSort('id');
    }
}
