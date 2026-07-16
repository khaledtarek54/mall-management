<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderPart;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\WorkOrderPartService;
use App\Support\ApprovalPolicy;
use App\Support\Modules;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Spare parts on a work order (FR-CM-09/10/11, FR-INV-04).
 *
 * An internal draw is **requested** and waits for approval — the stock only moves when
 * someone with the right authority for that value signs it off. An outside purchase is
 * recorded straight away: FR-CM-10 scopes approval to parts drawn from internal inventory.
 */
class WorkOrderPartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.preventive_maintenance.parts.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('preventive_maintenance')
            && Modules::enabled('inventory')
            && (auth()->user()?->can('preventive_maintenance.view') ?? false);
    }

    private function order(): MaintenanceWorkOrder
    {
        return $this->getOwnerRecord();
    }

    private function orderOpen(): bool
    {
        return ! $this->order()->isTerminal();
    }

    private function canRequest(): bool
    {
        return auth()->user()?->can('inventory.create') ?? false;
    }

    /**
     * May this user decide a draw of this value? Two questions, both required: the base
     * inventory right (ApprovalPolicy alone says "yes" to everyone when no bands are
     * configured), and then the tier the value demands.
     */
    private function canDecide(MaintenanceWorkOrderPart $part): bool
    {
        return auth()->user()?->can(WorkOrderPartService::DECIDE_PERMISSION)
            && ApprovalPolicy::canApprove(auth()->user(), ApprovalRule::MODULE_INVENTORY_DRAW, (float) $part->value);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'warehouse', 'vendor', 'requestedBy', 'decidedBy']))
            ->columns([
                TextColumn::make('source')
                    ->label(__('admin.preventive_maintenance.parts.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.parts.sources.{$state}"))
                    ->color(fn (string $state) => $state === 'internal' ? 'info' : 'warning'),
                TextColumn::make('part')
                    ->label(__('admin.preventive_maintenance.parts.part'))
                    ->state(fn (MaintenanceWorkOrderPart $record) => $record->label())
                    ->description(fn (MaintenanceWorkOrderPart $record) => $record->isInternal()
                        ? $record->warehouse?->name
                        : $record->vendor?->name),
                TextColumn::make('quantity')->label(__('admin.preventive_maintenance.parts.quantity')),
                TextColumn::make('value')
                    ->label(__('admin.preventive_maintenance.parts.value'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('status')
                    ->label(__('admin.preventive_maintenance.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.parts.statuses.{$state}"))
                    ->color(fn (string $state, MaintenanceWorkOrderPart $record) => match (true) {
                        $record->movementWasVoided() => 'gray',
                        in_array($state, ['approved', 'recorded'], true) => 'success',
                        $state === 'rejected' => 'danger',
                        default => 'warning',
                    })
                    // Says WHO is needed while it waits, so the request doesn't sit unseen
                    // because nobody knew it was theirs to action (FR-CM-11).
                    ->description(fn (MaintenanceWorkOrderPart $record) => match (true) {
                        $record->awaitingTierLabel() !== null => __('admin.preventive_maintenance.parts.awaiting', [
                            'tier' => $record->awaitingTierLabel(),
                        ]),
                        // The stock came back; saying "Issued" and nothing else would be a lie.
                        $record->movementWasVoided() => __('admin.preventive_maintenance.parts.movement_voided'),
                        $record->decidedBy !== null => $record->decidedBy->name,
                        default => null,
                    }),
            ])
            ->headerActions([
                // FR-CM-09 internal — a request, not a draw.
                Action::make('request_internal')
                    ->label(__('admin.preventive_maintenance.parts.request_internal'))
                    ->icon('heroicon-o-archive-box')
                    ->modalDescription(__('admin.preventive_maintenance.parts.request_internal_hint'))
                    ->visible(fn () => $this->orderOpen() && $this->canRequest())
                    ->authorize(fn () => $this->canRequest())
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('admin.preventive_maintenance.parts.warehouse'))
                            // The job's own property only — you cannot draw from another
                            // mall's shelf, and its warehouses are none of your business.
                            ->options(fn () => Warehouse::query()
                                ->where('asset_id', TenantScope::clampAssetId($this->order()->asset_id))
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->native(false),
                        Select::make('inventory_item_id')
                            ->label(__('admin.preventive_maintenance.parts.item'))
                            // The catalog is deliberately SHARED ("a pump seal is the same
                            // item everywhere"), so it is not property-filtered.
                            // Only the three columns the label needs — hydrating whole models
                            // just to concatenate two strings scales badly with the catalog.
                            ->options(fn () => InventoryItem::query()
                                ->where('is_active', true)
                                ->orderBy('sku')
                                ->get(['id', 'sku', 'name'])
                                ->mapWithKeys(fn (InventoryItem $i) => [$i->id => $i->sku.' — '.$i->name])
                                ->all())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false),
                        TextInput::make('quantity')
                            ->label(__('admin.preventive_maintenance.parts.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canRequest(), 403);

                        try {
                            $part = app(WorkOrderPartService::class)->requestInternal($this->order(), $data);
                        } catch (\DomainException|\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.preventive_maintenance.parts.requested_notice'))
                            ->body($part->awaitingTierLabel() === null ? null : __('admin.preventive_maintenance.parts.awaiting', [
                                'tier' => $part->awaitingTierLabel(),
                            ]))
                            ->success()
                            ->send();
                    }),

                // FR-CM-09 external — recorded, not approved.
                Action::make('record_external')
                    ->label(__('admin.preventive_maintenance.parts.record_external'))
                    ->icon('heroicon-o-shopping-cart')
                    ->color('gray')
                    ->modalDescription(__('admin.preventive_maintenance.parts.record_external_hint'))
                    ->visible(fn () => $this->orderOpen() && $this->canRequest())
                    ->authorize(fn () => $this->canRequest())
                    ->schema([
                        TextInput::make('description')
                            ->label(__('admin.preventive_maintenance.parts.description'))
                            ->required()
                            ->maxLength(255),
                        Select::make('vendor_id')
                            ->label(__('admin.preventive_maintenance.fields.vendor'))
                            ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->native(false),
                        TextInput::make('reference')
                            ->label(__('admin.preventive_maintenance.parts.reference'))
                            ->maxLength(100),
                        TextInput::make('quantity')
                            ->label(__('admin.preventive_maintenance.parts.quantity'))
                            ->numeric()->minValue(0.001)->required(),
                        TextInput::make('unit_cost')
                            ->label(__('admin.preventive_maintenance.parts.unit_cost'))
                            ->prefix('EGP')
                            ->numeric()->minValue(0)->required(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canRequest(), 403);

                        try {
                            app(WorkOrderPartService::class)->recordExternal($this->order(), $data);
                        } catch (\DomainException|\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.parts.recorded_notice'))->success()->send();
                    }),
            ])
            ->recordActions([
                // FR-CM-10/11 — shown only to someone whose authority actually covers this
                // part's value, so the button isn't an invitation to be refused.
                Action::make('approve')
                    ->label(__('admin.preventive_maintenance.parts.approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MaintenanceWorkOrderPart $record) => $record->isPending()
                        && $this->canDecide($record)
                        && (int) $record->requested_by_user_id !== (int) auth()->id())
                    ->authorize(fn (MaintenanceWorkOrderPart $record) => $this->canDecide($record))
                    ->action(function (MaintenanceWorkOrderPart $record): void {
                        try {
                            app(WorkOrderPartService::class)->approve($record);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.parts.approved_notice'))->success()->send();
                    }),
                // A typo correction on an external record — see WorkOrderPartService::remove().
                Action::make('remove')
                    ->label(__('admin.preventive_maintenance.parts.remove'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalDescription(__('admin.preventive_maintenance.parts.remove_hint'))
                    ->visible(fn (MaintenanceWorkOrderPart $record) => ! $record->isInternal()
                        && $this->orderOpen()
                        && (auth()->user()?->can(WorkOrderPartService::DECIDE_PERMISSION) ?? false))
                    ->authorize(fn () => auth()->user()?->can(WorkOrderPartService::DECIDE_PERMISSION) ?? false)
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.preventive_maintenance.parts.remove_reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (MaintenanceWorkOrderPart $record, array $data): void {
                        try {
                            app(WorkOrderPartService::class)->remove($record, $data['reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.parts.removed_notice'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.preventive_maintenance.parts.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (MaintenanceWorkOrderPart $record) => $record->isPending() && $this->canDecide($record))
                    ->authorize(fn (MaintenanceWorkOrderPart $record) => $this->canDecide($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.preventive_maintenance.parts.reject_reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (MaintenanceWorkOrderPart $record, array $data): void {
                        try {
                            app(WorkOrderPartService::class)->reject($record, $data['reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.preventive_maintenance.parts.rejected_notice'))->success()->send();
                    }),
            ])
            ->defaultSort('id');
    }
}
