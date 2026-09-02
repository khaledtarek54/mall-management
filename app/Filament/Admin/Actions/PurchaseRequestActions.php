<?php

namespace App\Filament\Admin\Actions;

use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\PurchaseRequestService;
use App\Support\ApprovalPolicy;
use App\Support\Filament\EntitySelect;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a purchase request, defined once.**
 *
 * `approve`, `reject`, `order`, `receive` and `cancel` lived inline in `PurchaseRequestsTable`,
 * so they were reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform these acts can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class PurchaseRequestActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // **SUBMIT — the act that was built and never wired.** `PurchaseRequestService::submit()`
            // existed, refused a non-draft, refused an empty request and stamped the submitter as
            // the person taking responsibility — and had no caller anywhere but a test. So a DRAFT
            // purchase request was a dead end: `inventory:scan-low-stock` raises one automatically,
            // the lines relation manager locks editing to `requested`, and nothing on any screen
            // could move it out of draft. The whole reorder loop stopped at the first step.
            Action::make('submit')
                ->label(__('admin.procurement.actions.submit'))
                ->icon('heroicon-o-paper-airplane')->color('primary')
                ->requiresConfirmation()
                ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_DRAFT
                    && (auth()->user()?->can(PurchaseRequestService::REQUEST_PERMISSION) ?? false))
                ->authorize(fn () => auth()->user()?->can(PurchaseRequestService::REQUEST_PERMISSION) ?? false)
                ->action(function (PurchaseRequest $record) {
                    abort_unless(auth()->user()?->can(PurchaseRequestService::REQUEST_PERMISSION) ?? false, 403);

                    try {
                        app(PurchaseRequestService::class)->submit($record);
                    } catch (\DomainException $e) {
                        // Same shape as every sibling here: a refusal is worded for the operator
                        // rather than thrown at them. `submit()` refuses a non-draft and an EMPTY
                        // request — the latter is a real state, since a draft raised by
                        // `inventory:scan-low-stock` can legitimately end up with no lines once the
                        // shortages it was raised for resolve themselves.
                        self::notifyFailure($e);

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.procurement.notices.submitted'))
                        ->success()
                        ->send();
                }),

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
                        self::notifyFailure($e);

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
                        self::notifyFailure($e);

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
                    EntitySelect::make('vendor_id')
                        ->label(__('admin.procurement.fields.vendor'))
                        // Vendors are a SHARED catalog (PropertyIsolation), so unscoped —
                        // matching FacilityWorkOrderForm.
                        ->entity(Vendor::class),
                    TextInput::make('order_reference')
                        ->label(__('admin.procurement.fields.order_reference'))->maxLength(100),
                ])
                ->action(function (PurchaseRequest $record, array $data): void {
                    try {
                        $ordered = app(PurchaseRequestService::class)->order($record, $data['vendor_id'] ?? null, $data['order_reference'] ?? null);
                    } catch (\DomainException $e) {
                        self::notifyFailure($e);

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
                ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_ORDERED && self::canReceive())
                ->authorize(fn () => self::canReceive())
                ->action(function (PurchaseRequest $record): void {
                    try {
                        $received = app(PurchaseRequestService::class)->receive($record);
                    } catch (\DomainException $e) {
                        self::notifyFailure($e);

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
                        self::notifyFailure($e);

                        return;
                    }
                    Notification::make()->title(__('admin.procurement.notices.cancelled'))->success()->send();
                }),
        ];
    }

    /** Two questions, both required: may you decide at all, and does your tier cover the value? */
    public static function canDecide(PurchaseRequest $record): bool
    {
        return (auth()->user()?->can(PurchaseRequestService::DECIDE_PERMISSION) ?? false)
            && ApprovalPolicy::canApprove(auth()->user(), ApprovalRule::MODULE_PURCHASE_REQUEST, (float) $record->total_value);
    }

    public static function canReceive(): bool
    {
        return auth()->user()?->can(PurchaseRequestService::RECEIVE_PERMISSION) ?? false;
    }

    public static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }
}
