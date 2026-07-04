<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The checklist on a preventive-maintenance work order (module 26). Engineers tick each
 * item done (captured with who/when); items can be added/removed while the order is
 * open. Ticking is gated on `preventive_maintenance.complete`; editing the list on
 * `preventive_maintenance.edit`. A terminal (done/cancelled) order's checklist is frozen.
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
            ->modifyQueryUsing(fn ($query) => $query->with('doneBy'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin.preventive_maintenance.fields.label'))
                    ->wrap(),
                ToggleColumn::make('is_done')
                    ->label(__('admin.preventive_maintenance.fields.done'))
                    // Tickable only by a user who may complete work, on a non-terminal order.
                    ->disabled(fn () => ! $this->orderEditable() || ! (auth()->user()?->can('preventive_maintenance.complete') ?? false))
                    ->updateStateUsing(function (MaintenanceWorkOrderItem $record, bool $state): void {
                        abort_unless((auth()->user()?->can('preventive_maintenance.complete') ?? false) && $this->orderEditable(), 403);
                        $record->update([
                            'is_done' => $state,
                            'done_at' => $state ? now() : null,
                            'done_by_user_id' => $state ? auth()->id() : null,
                        ]);
                    }),
                TextColumn::make('doneBy.name')
                    ->label(__('admin.preventive_maintenance.fields.completed_by'))
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
                    ->action(function (array $data): void {
                        abort_unless((auth()->user()?->can('preventive_maintenance.edit') ?? false) && $this->orderEditable(), 403);
                        /** @var MaintenanceWorkOrder $order */
                        $order = $this->getOwnerRecord();
                        $order->items()->create(['label' => $data['label']]);
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn () => $this->orderEditable() && (auth()->user()?->can('preventive_maintenance.edit') ?? false))
                    ->before(fn () => abort_unless((auth()->user()?->can('preventive_maintenance.edit') ?? false) && $this->orderEditable(), 403)),
            ])
            ->defaultSort('id');
    }
}
