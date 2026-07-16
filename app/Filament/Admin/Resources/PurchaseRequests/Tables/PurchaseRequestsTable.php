<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Tables;

use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\PurchaseRequestService;
use App\Support\ApprovalPolicy;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequestsTable
{
    /** Two questions, both required: may you decide at all, and does your tier cover the value? */
    private static function canDecide(PurchaseRequest $record): bool
    {
        return (auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false)
            && ApprovalPolicy::canApprove(auth()->user(), ApprovalRule::MODULE_PURCHASE_REQUEST, (float) $record->total_value);
    }

    private static function canReceive(): bool
    {
        return auth()->user()?->can(PurchaseRequestService::RECEIVE_PERMISSION) ?? false;
    }

    private static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'vendor', 'warehouse', 'requestedBy']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.procurement.fields.reference'))->searchable()->sortable(),
                TextColumn::make('asset.name')->label(__('admin.procurement.fields.asset'))->toggleable(),
                TextColumn::make('total_value')
                    ->label(__('admin.procurement.fields.total_value'))
                    ->money('EGP')->alignRight()->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.procurement.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.procurement.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'received' => 'success',
                        'approved', 'ordered' => 'info',
                        'rejected', 'cancelled' => 'danger',
                        default => 'warning',
                    })
                    // While it waits, say WHO it waits for — otherwise a request sits unseen
                    // because nobody knew it was theirs to action (FR-PROC-02).
                    ->description(fn (PurchaseRequest $record) => $record->status === PurchaseRequest::STATUS_REQUESTED
                        ? __('admin.procurement.awaiting', ['tier' => static::tierLabel($record)])
                        : $record->vendor?->name),
                TextColumn::make('requestedBy.name')->label(__('admin.procurement.fields.requested_by'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('admin.procurement.fields.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.procurement.fields.status'))
                    ->options(fn () => collect(PurchaseRequest::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.procurement.statuses.{$s}")])->all()),
            ])
            ->recordActions([
                // FR-PROC-02 — approval, and it is what unlocks ordering.
                Action::make('approve')
                    ->label(__('admin.procurement.actions.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                        && static::canDecide($r)
                        && (int) $r->requested_by_user_id !== (int) auth()->id())
                    ->authorize(fn (PurchaseRequest $r) => static::canDecide($r))
                    ->schema([Textarea::make('notes')->label(__('admin.procurement.fields.notes'))->rows(2)])
                    ->action(function (PurchaseRequest $record, array $data): void {
                        try {
                            app(PurchaseRequestService::class)->approve($record, $data['notes'] ?? null);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.procurement.notices.approved'))->success()->send();
                    }),

                Action::make('reject')
                    ->label(__('admin.procurement.actions.reject'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED && static::canDecide($r))
                    ->authorize(fn (PurchaseRequest $r) => static::canDecide($r))
                    ->schema([Textarea::make('reason')->label(__('admin.procurement.reject_reason'))->required()->rows(2)])
                    ->action(function (PurchaseRequest $record, array $data): void {
                        try {
                            app(PurchaseRequestService::class)->reject($record, $data['reason']);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.procurement.notices.rejected'))->success()->send();
                    }),

                // Only ever reachable from `approved` — the transition matrix is FR-PROC-02.
                Action::make('order')
                    ->label(__('admin.procurement.actions.order'))
                    ->icon('heroicon-o-paper-airplane')->color('info')
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_APPROVED
                        && (auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false))
                    ->authorize(fn () => auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false)
                    ->schema([
                        Select::make('vendor_id')
                            ->label(__('admin.procurement.fields.vendor'))
                            // Vendors are a SHARED catalog (PropertyIsolation), so unscoped —
                            // matching MaintenanceWorkOrderForm.
                            ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->native(false),
                        TextInput::make('order_reference')
                            ->label(__('admin.procurement.fields.order_reference'))->maxLength(100),
                    ])
                    ->action(function (PurchaseRequest $record, array $data): void {
                        try {
                            app(PurchaseRequestService::class)->order($record, $data['vendor_id'] ?? null, $data['order_reference'] ?? null);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.procurement.notices.ordered'))->success()->send();
                    }),

                // FR-PROC-04 — this is the action that puts stock on the shelf, linked (FR-WH-02).
                Action::make('receive')
                    ->label(__('admin.procurement.actions.receive'))
                    ->icon('heroicon-o-inbox-arrow-down')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.procurement.receive_hint'))
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_ORDERED && static::canReceive())
                    ->authorize(fn () => static::canReceive())
                    ->action(function (PurchaseRequest $record): void {
                        try {
                            app(PurchaseRequestService::class)->receive($record);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.procurement.notices.received'))->success()->send();
                    }),

                Action::make('cancel')
                    ->label(__('admin.procurement.actions.cancel'))
                    ->icon('heroicon-o-no-symbol')->color('gray')
                    ->visible(fn (PurchaseRequest $r) => ! $r->isTerminal()
                        && (auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false))
                    ->authorize(fn () => auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false)
                    ->schema([Textarea::make('reason')->label(__('admin.procurement.cancel_reason'))->required()->rows(2)])
                    ->action(function (PurchaseRequest $record, array $data): void {
                        try {
                            app(PurchaseRequestService::class)->cancel($record, $data['reason']);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.procurement.notices.cancelled'))->success()->send();
                    }),

                EditAction::make()->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                    && PurchaseRequestResource::canEdit($r)),
            ])
            ->defaultSort('id', 'desc');
    }

    /** The stored tier is a permission (`approvals.tier_1`); a dotted key can never resolve. */
    private static function tierLabel(PurchaseRequest $record): string
    {
        $tier = str_replace('approvals.', '', (string) $record->required_permission);

        if (! in_array($record->required_permission, ApprovalRule::TIERS, true)) {
            $tier = 'unknown';
        }

        return __("admin.procurement.tiers.{$tier}");
    }
}
