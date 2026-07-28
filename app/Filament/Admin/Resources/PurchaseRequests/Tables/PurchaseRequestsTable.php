<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Tables;

use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\PurchaseOrderPdfService;
use App\Services\PurchaseRequestService;
use App\Support\ApprovalPolicy;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
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
                TextColumn::make('po_number')
                    ->label(__('admin.procurement.fields.po_number'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('asset.name')->label(__('admin.procurement.fields.asset'))->toggleable(),
                TextColumn::make('total_value')
                    ->label(__('admin.procurement.fields.total_value'))
                    ->money('EGP')->alignRight()->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
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
                    ->description(function (PurchaseRequest $record): ?string {
                        if ($record->status === PurchaseRequest::STATUS_REQUESTED) {
                            return __('admin.procurement.awaiting', ['tier' => self::tierLabel($record)]);
                        }
                        // Nullable by design — an approved request has no vendor until it is ordered.
                        /** @var Vendor|null $vendor */
                        $vendor = $record->vendor;

                        return $vendor?->name;
                    }),
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
                        && self::canDecide($r)
                        && (int) $r->requested_by_user_id !== (int) auth()->id())
                    ->authorize(fn (PurchaseRequest $r) => self::canDecide($r))
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
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED && self::canDecide($r))
                    ->authorize(fn (PurchaseRequest $r) => self::canDecide($r))
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
                    // Same two-question gate as approve/reject: the tier must cover the value.
                    // The service re-checks it (assertMayDecideValue), so this is honest-UI parity
                    // — a low-tier decider no longer sees an Order button they can't action (M29-3).
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_APPROVED
                        && self::canDecide($r))
                    ->authorize(fn (PurchaseRequest $r) => self::canDecide($r))
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
                            $ordered = app(PurchaseRequestService::class)->order($record, $data['vendor_id'] ?? null, $data['order_reference'] ?? null);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        // Feedback with the resulting state: the PO number now exists + who it went
                        // to, so the operator knows the PO is ready to download and send.
                        Notification::make()
                            ->title(__('admin.procurement.notices.ordered'))
                            ->body(__('admin.procurement.notices.ordered_body', [
                                'po' => $ordered->po_number,
                                // vendor is optional on an order — the picker isn't required.
                                'vendor' => optional($ordered->vendor)->name ?? '—',
                            ]))
                            ->success()
                            ->send();
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
                            $received = app(PurchaseRequestService::class)->receive($record);
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        // Feedback with resulting state: how many stockable lines landed where, so
                        // the operator sees stock actually moved (not just "received").
                        $stockedCount = $received->stockableLines()->whereNotNull('stock_movement_id')->count();
                        Notification::make()
                            ->title(__('admin.procurement.notices.received'))
                            ->body($stockedCount > 0
                                ? __('admin.procurement.notices.received_body', [
                                    'count' => $stockedCount,
                                    'warehouse' => optional($received->warehouse)->name ?? '—',
                                ])
                                : __('admin.procurement.notices.received_services'))
                            ->success()
                            ->send();
                    }),

                Action::make('cancel')
                    ->label(__('admin.procurement.actions.cancel'))
                    ->icon('heroicon-o-no-symbol')->color('gray')
                    // Cancelling unwinds a commitment (an approved+ordered purchase), so it carries
                    // the same authority as approving it — the tier, not just the base permission.
                    // The service enforces it (assertMayDecideValue); this keeps the button honest
                    // for a low-tier decider (M29-3).
                    ->visible(fn (PurchaseRequest $r) => ! $r->isTerminal() && self::canDecide($r))
                    ->authorize(fn (PurchaseRequest $r) => self::canDecide($r))
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

                // authorize(), not just visible(): visible() is a display gate, not a dispatch
                // gate (mountAction checks isDisabled(), never isVisible()), so without this the
                // status/permission gate is bypassable via mountAction('edit', $id). Mirrors the
                // five sibling actions above (D-95).
                // authorize() mirrors visible() to match the five sibling actions above (D-95).
                // In this Filament build visible() already refuses the mount (a hidden table
                // action does not mount), so this is defence-in-depth + consistency rather than a
                // live-exploit fix: it keeps the gate holding if a future Filament decouples mount
                // from visibility (the framework's documented `mountAction` checks isDisabled()).
                EditAction::make()
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                        && PurchaseRequestResource::canEdit($r))
                    ->authorize(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                        && PurchaseRequestResource::canEdit($r)),

                // "View working" for the total + which approval tier it fell into — an operator
                // approving EGP 50k should see the lines that make the number and why it needs the
                // manager it needs, not trust a bare figure. Native infolist, per convention.
                Action::make('breakdown')
                    ->label(__('admin.procurement.actions.view_working'))
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (PurchaseRequest $record) => [
                        TextEntry::make('lines_working')
                            ->label(__('admin.procurement.fields.items'))
                            ->state(function () use ($record) {
                                $rows = $record->lines()->with('item')->get();

                                if ($rows->isEmpty()) {
                                    return '—';
                                }

                                return $rows->map(fn ($l) => sprintf(
                                    '%s · %s × %s = EGP %s',
                                    optional($l->item)->name ?? $l->description ?? '—',
                                    rtrim(rtrim(number_format((float) $l->quantity, 3), '0'), '.'),
                                    number_format((float) $l->unit_cost, 2),
                                    number_format((float) $l->line_value, 2),
                                ))->join("\n");
                            }),
                        TextEntry::make('total_working')
                            ->label(__('admin.procurement.fields.total_value'))
                            ->state(fn () => 'EGP '.number_format((float) $record->total_value, 2)),
                        TextEntry::make('tier_working')
                            ->label(__('admin.procurement.fields.approval_tier'))
                            ->state(fn () => self::tierLabel($record))
                            ->helperText(__('admin.procurement.tier_hint')),
                    ]),

                // The Purchase Order document — the whole point of "ordering". Available once the
                // request has become an order (ordered or received), so there is a PO to render.
                Action::make('downloadPo')
                    ->label(__('admin.procurement.actions.download_po'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (PurchaseRequest $r) => in_array($r->status, [PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_RECEIVED], true))
                    ->action(function (PurchaseRequest $record) {
                        $svc = app(PurchaseOrderPdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
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
