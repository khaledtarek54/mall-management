<?php

namespace App\Filament\Admin\Actions;

use App\Jobs\SubmitInvoiceToEta;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\AllocatePaymentToInvoiceItemsService;
use App\Services\DisputeInvoiceItemService;
use App\Support\Modules;
use App\Support\OpsLog;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * **Everything you can DO to a invoice, defined once.**
 *
 * `disputeline`, `resolvedispute`, `allocatetolines`, `regeneratepaymentlink` and `submittoeta` lived inline in `InvoicesTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class InvoiceActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // ── Dispute a line (MF-07) ────────────────────────────────────────────────────
            // The late-fee sweep charged a penalty on the whole balance, including a service
            // charge the tenant had formally disputed — which is the complaint that starts an
            // argument about the fee on top of the argument about the charge.
            Action::make('disputeLine')
                ->label(__('admin.actions.dispute_line'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->modalHeading(fn (Invoice $record) => __('admin.actions.dispute_line').' · '.$record->number)
                ->modalDescription(__('admin.actions.dispute_line_hint'))
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('invoices.edit') ?? false)
                    && ! in_array($record->status, ['cancelled', 'written_off'], true))
                ->authorize(fn (): bool => auth()->user()?->can('invoices.edit') ?? false)
                ->schema(fn (Invoice $record): array => [
                    Select::make('invoice_item_id')
                        ->label(__('admin.sections.invoice_items'))
                        ->options(fn (): array => self::lineOptions($record))
                        ->native(false)
                        ->required(),
                    // Required: this flag suppresses a fee, so it has to say why. The first
                    // question anyone asks three months later is exactly this.
                    Textarea::make('reason')
                        ->label(__('admin.actions.dispute_line_reason'))
                        ->placeholder(__('admin.actions.dispute_line_reason_placeholder'))
                        ->rows(2)
                        ->required(),
                ])
                ->action(function (Invoice $record, array $data) {
                    abort_unless(auth()->user()?->can('invoices.edit') ?? false, 403);

                    /** @var InvoiceItem $item */
                    $item = $record->items()->findOrFail($data['invoice_item_id']);

                    try {
                        app(DisputeInvoiceItemService::class)->dispute($item, $data['reason']);
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('admin.actions.dispute_line_raised'))->send();
                }),
            Action::make('resolveDispute')
                ->label(__('admin.actions.resolve_dispute'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.resolve_dispute_confirm'))
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('invoices.edit') ?? false)
                    && $record->items()->whereNotNull('disputed_at')->exists())
                ->authorize(fn (): bool => auth()->user()?->can('invoices.edit') ?? false)
                ->schema(fn (Invoice $record): array => [
                    Select::make('invoice_item_id')
                        ->label(__('admin.sections.invoice_items'))
                        ->options(fn (): array => self::disputedLineOptions($record))
                        ->native(false)
                        ->required(),
                ])
                ->action(function (Invoice $record, array $data) {
                    abort_unless(auth()->user()?->can('invoices.edit') ?? false, 403);

                    /** @var InvoiceItem $item */
                    $item = $record->items()->findOrFail($data['invoice_item_id']);

                    try {
                        app(DisputeInvoiceItemService::class)->resolve($item);
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('admin.actions.resolve_dispute_done'))->send();
                }),
            // ── Which lines did this payment settle? (MF-06) ──────────────────────────────
            // Without this the item split exists only in the service, and the aging-by-charge-type
            // report can only ever show the priority order's guess. The operator types what the
            // remittance advice said — "this is the CAM, we are still arguing about the rent" —
            // and the aging stops blaming the wrong line.
            Action::make('allocateToLines')
                ->label(__('admin.actions.allocate_to_lines'))
                ->icon('heroicon-o-scale')
                ->color('gray')
                ->modalHeading(fn (Invoice $record) => __('admin.actions.allocate_to_lines').' · '.$record->number)
                ->modalDescription(__('admin.actions.allocate_to_lines_hint'))
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('invoices.edit') ?? false)
                    && $record->receivedPayments()->exists())
                ->authorize(fn (): bool => auth()->user()?->can('invoices.edit') ?? false)
                ->schema(fn (Invoice $record): array => self::paymentSplitSchema($record))
                ->action(function (Invoice $record, array $data) {
                    abort_unless(auth()->user()?->can('invoices.edit') ?? false, 403);

                    $payment = Payment::findOrFail($data['payment_id']);

                    try {
                        app(AllocatePaymentToInvoiceItemsService::class)
                            ->apply($payment, $record, $data['items'] ?? []);
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.actions.allocate_to_lines_saved'))
                        ->send();
                }),
            // The pay link is a bearer credential with no expiry: whoever holds the URL
            // can read the tenant, the line items and the amounts, with no login. Without
            // this the operator has NO remedy when one leaks — a forwarded mail, a shared
            // inbox, a screenshot. Rotating mints a new token and kills every old URL.
            Action::make('regeneratePaymentLink')
                ->label(__('admin.actions.regenerate_payment_link'))
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.regenerate_payment_link_confirm'))
                // Available whenever a token exists — NOT only while payable. A leaked link
                // to a settled invoice still discloses the tenant and the amounts, so the
                // remedy must outlive payability.
                ->visible(fn (Invoice $record) => filled($record->payment_link_token)
                    && (auth()->user()?->can('invoices.edit') ?? false))
                // Stated intent as well as the gate below. This came off the copy of this action
                // that used to live inline on EditInvoice — the page composed BOTH, so the operator
                // read "Regenerate payment link" twice in the header (2026-09-01). One definition
                // survives; the authorisation the other declared survives with it.
                ->authorize(fn (): bool => auth()->user()?->can('invoices.edit') ?? false)
                ->action(function (Invoice $record): void {
                    // The real gate: mountAction() ignores visible(), so revoking a client's
                    // access to their invoice must not be dispatchable without invoices.edit.
                    abort_unless(auth()->user()?->can('invoices.edit') ?? false, 403);

                    $record->rotatePaymentLinkToken();

                    // Revoking access to a financial document is worth a trace: a client
                    // reporting "the link stopped working" is otherwise unanswerable.
                    OpsLog::info('invoice.pay_link_rotated', [
                        'invoice_id' => $record->id,
                        'invoice_number' => $record->number,
                        'by' => auth()->id(),
                    ]);

                    Notification::make()
                        ->title(__('admin.actions.regenerate_payment_link_done'))
                        ->body(__('admin.actions.regenerate_payment_link_done_body', ['number' => $record->number]))
                        ->success()
                        ->send();
                }),
            // REMOVED 2026-08-11 — "Send WhatsApp" was a stub whose entire action() was a
            // success notification. Nothing was sent, and there is no WhatsApp client in this
            // codebase. The operator could enable it from Settings (the toggle was genuinely
            // wired), click it on an overdue invoice, and be told the tenant had been chased.
            // A button that reports a false result is worse than a missing feature: it makes
            // the collections record a lie. Rebuild it against a real provider, or leave it
            // out. `tenants.whatsapp` stays — the number is real data.
            Action::make('submitToEta')
                ->label(__('admin.actions.submit_to_eta'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (Invoice $record) => Modules::enabled('eta') && $record->eta_status !== 'valid' && in_array($record->status, ['issued', 'partially_paid', 'paid', 'overdue']) && auth()->user()?->can('invoices.submit_to_eta'))
                ->authorize(fn () => (auth()->user()?->can('invoices.submit_to_eta') ?? false) && Modules::enabled('eta'))
                ->requiresConfirmation()
                ->modalDescription(fn () => config('eta.mock')
                    ? __('admin.actions.submit_to_eta_modal_mock')
                    : __('admin.actions.submit_to_eta_modal_live'))
                ->action(function (Invoice $record): void {
                    // action() is the real gate — mountAction() ignores visible(); the ETA filing job
                    // must not be dispatchable without the permission (and the module enabled).
                    abort_unless((auth()->user()?->can('invoices.submit_to_eta') ?? false) && Modules::enabled('eta'), 403);
                    // Queue the submission — the ETA gateway can be slow when live,
                    // so never block the request on it. The job retries with backoff
                    // and surfaces exhaustion (see App\Jobs\SubmitInvoiceToEta).
                    SubmitInvoiceToEta::dispatch($record);
                    Notification::make()
                        ->title(__('admin.notifications.eta_queued'))
                        ->body(__('admin.notifications.eta_queued_body', ['number' => $record->number]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<int, string> */
    public static function disputedLineOptions(Invoice $record): array
    {
        /** @var Collection<int, InvoiceItem> $all */
        $all = $record->items;

        $items = $all->filter(fn (InvoiceItem $i): bool => $i->isDisputed());

        return $items->mapWithKeys(fn (InvoiceItem $i): array => [
            $i->id => $i->description.' · '.($i->disputed_reason ?? ''),
        ])->all();
    }

    /**
     * Every line on the invoice, labelled with its amount.
     *
     * @return array<int, string>
     */
    public static function lineOptions(Invoice $record): array
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = $record->items;

        return $items->mapWithKeys(fn (InvoiceItem $i): array => [
            $i->id => $i->description.' · EGP '.number_format((float) $i->total, 2)
                .($i->isDisputed() ? ' · '.__('admin.reports.disputed') : ''),
        ])->all();
    }

    /**
     * The "which lines did this payment settle?" form (MF-06).
     *
     * A method rather than an inline closure so the collections can carry the `@var` annotations
     * `Invoice::payments()` / `items()` do not — otherwise every property read here is a
     * static-analysis error against a bare `Model`.
     *
     * @return array<int, Field>
     */
    public static function paymentSplitSchema(Invoice $record): array
    {
        /** @var Collection<int, Payment> $payments */
        $payments = $record->receivedPayments()->get();

        /** @var Collection<int, InvoiceItem> $items */
        $items = $record->items;

        // Read the allocation from the pivot table rather than the loaded `pivot` attribute: it is
        // one query either way, and the relation carries no declared pivot type to read through.
        $allocated = DB::table('invoice_payment')
            ->where('invoice_id', $record->id)
            ->pluck('allocated_amount', 'payment_id');

        $options = $payments->mapWithKeys(fn (Payment $p): array => [
            $p->id => $p->reference.' · EGP '
                .number_format((float) ($allocated[$p->id] ?? 0), 2)
                .' · '.$p->payment_date->format('d/m/Y'),
        ])->all();

        return [
            Select::make('payment_id')
                ->label(__('admin.resources.payment.singular'))
                ->options($options)
                ->native(false)
                ->required(),
            ...$items->map(fn (InvoiceItem $item): TextInput => TextInput::make("items.{$item->id}")
                ->label($item->description)
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->maxValue((float) $item->total)
                // Blank, not prefilled: a prefilled figure is a claim about what the tenant paid
                // for, and inventing that claim is the bug this action exists to fix.
                ->placeholder(number_format((float) $item->total, 2))
                ->helperText(__('admin.actions.allocate_to_lines_line_hint', [
                    'total' => number_format((float) $item->total, 2),
                ])))->values()->all(),
        ];
    }
}
