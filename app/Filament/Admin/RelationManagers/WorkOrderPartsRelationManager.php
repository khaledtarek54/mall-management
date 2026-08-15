<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderPart;
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
        return __('admin.facility.parts.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('facility')
            && Modules::enabled('inventory')
            && (auth()->user()?->can('facility.view') ?? false);
    }

    private function order(): FacilityWorkOrder
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
    private function canDecide(FacilityWorkOrderPart $part): bool
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
                    ->label(__('admin.facility.parts.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.parts.sources.{$state}"))
                    ->color(fn (string $state) => $state === 'internal' ? 'info' : 'warning'),
                TextColumn::make('part')
                    ->label(__('admin.facility.parts.part'))
                    ->state(fn (FacilityWorkOrderPart $record) => $record->label())
                    ->description(fn (FacilityWorkOrderPart $record) => $record->isInternal()
                        ? $record->warehouse?->name
                        : $record->vendor?->name),
                TextColumn::make('quantity')->label(__('admin.facility.parts.quantity')),
                TextColumn::make('value')
                    ->label(__('admin.facility.parts.value'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('status')
                    ->label(__('admin.facility.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.parts.statuses.{$state}"))
                    ->color(fn (string $state, FacilityWorkOrderPart $record) => match (true) {
                        $record->movementWasVoided() => 'gray',
                        in_array($state, ['approved', 'recorded'], true) => 'success',
                        $state === 'rejected' => 'danger',
                        default => 'warning',
                    })
                    // Says WHO is needed while it waits, so the request doesn't sit unseen
                    // because nobody knew it was theirs to action (FR-CM-11).
                    ->description(fn (FacilityWorkOrderPart $record) => match (true) {
                        $record->awaitingTierLabel() !== null => __('admin.facility.parts.awaiting', [
                            'tier' => $record->awaitingTierLabel(),
                        ]),
                        // The stock came back; saying "Issued" and nothing else would be a lie.
                        $record->movementWasVoided() => __('admin.facility.parts.movement_voided'),
                        $record->decidedBy !== null => $record->decidedBy->name,
                        default => null,
                    }),
            ])
            ->headerActions([
                // FR-CM-09 internal — a request, not a draw.
                Action::make('request_internal')
                    ->label(__('admin.facility.parts.request_internal'))
                    ->icon('heroicon-o-archive-box')
                    ->modalDescription(__('admin.facility.parts.request_internal_hint'))
                    ->visible(fn () => $this->orderOpen() && $this->canRequest())
                    ->authorize(fn () => $this->canRequest())
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('admin.facility.parts.warehouse'))
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
                            ->label(__('admin.facility.parts.item'))
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
                            ->label(__('admin.facility.parts.quantity'))
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
                            ->title(__('admin.facility.parts.requested_notice'))
                            ->body($part->awaitingTierLabel() === null ? null : __('admin.facility.parts.awaiting', [
                                'tier' => $part->awaitingTierLabel(),
                            ]))
                            ->success()
                            ->send();
                    }),

                // FR-CM-09 external — recorded, not approved.
                Action::make('record_external')
                    ->label(__('admin.facility.parts.record_external'))
                    ->icon('heroicon-o-shopping-cart')
                    ->color('gray')
                    ->modalDescription(__('admin.facility.parts.record_external_hint'))
                    ->visible(fn () => $this->orderOpen() && $this->canRequest())
                    ->authorize(fn () => $this->canRequest())
                    ->schema([
                        TextInput::make('description')
                            ->label(__('admin.facility.parts.description'))
                            ->required()
                            ->maxLength(255),
                        Select::make('vendor_id')
                            ->label(__('admin.facility.fields.vendor'))
                            ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->native(false),
                        TextInput::make('reference')
                            ->label(__('admin.facility.parts.reference'))
                            ->maxLength(100),
                        TextInput::make('quantity')
                            ->label(__('admin.facility.parts.quantity'))
                            ->numeric()->minValue(0.001)->required(),
                        TextInput::make('unit_cost')
                            ->label(__('admin.facility.parts.unit_cost'))
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

                        Notification::make()->title(__('admin.facility.parts.recorded_notice'))->success()->send();
                    }),
            ])
            ->recordActions([
                // FR-CM-10/11 — shown only to someone whose authority actually covers this
                // part's value, so the button isn't an invitation to be refused.
                Action::make('approve')
                    ->label(__('admin.facility.parts.approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FacilityWorkOrderPart $record) => $record->isPending()
                        && $this->canDecide($record)
                        && (int) $record->requested_by_user_id !== (int) auth()->id())
                    ->authorize(fn (FacilityWorkOrderPart $record) => $this->canDecide($record))
                    ->action(function (FacilityWorkOrderPart $record): void {
                        try {
                            app(WorkOrderPartService::class)->approve($record);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.parts.approved_notice'))->success()->send();
                    }),
                // A typo correction on an external record — see WorkOrderPartService::remove().
                Action::make('remove')
                    ->label(__('admin.facility.parts.remove'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalDescription(__('admin.facility.parts.remove_hint'))
                    ->visible(fn (FacilityWorkOrderPart $record) => ! $record->isInternal()
                        && $this->orderOpen()
                        && (auth()->user()?->can(WorkOrderPartService::DECIDE_PERMISSION) ?? false))
                    ->authorize(fn () => auth()->user()?->can(WorkOrderPartService::DECIDE_PERMISSION) ?? false)
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.facility.parts.remove_reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (FacilityWorkOrderPart $record, array $data): void {
                        try {
                            app(WorkOrderPartService::class)->remove($record, $data['reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.parts.removed_notice'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.facility.parts.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (FacilityWorkOrderPart $record) => $record->isPending() && $this->canDecide($record))
                    ->authorize(fn (FacilityWorkOrderPart $record) => $this->canDecide($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.facility.parts.reject_reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (FacilityWorkOrderPart $record, array $data): void {
                        try {
                            app(WorkOrderPartService::class)->reject($record, $data['reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.parts.rejected_notice'))->success()->send();
                    }),
            ])
            ->defaultSort('id');
    }
}
