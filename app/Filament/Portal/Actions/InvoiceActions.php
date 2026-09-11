<?php

namespace App\Filament\Portal\Actions;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\DemoPayments;
use App\Support\Portal;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a tenant may DO to an invoice, defined once for the row and the record page.
 *
 * **The portal's twin of `App\Filament\Admin\Actions\*Actions`, and it exists because the split
 * had already bitten.** *Pay now* and *Pay (demo)* were written out twice — on `ViewInvoice`'s
 * header and again in `InvoicesTable`'s row — each with its own predicate. On 2026-09-01 the View
 * page's `canPayDemo()` was routed to `Invoice::isPayable()`, the one register for *which invoice
 * may take money*; the table's `payDemo` copy was not (its `payNow` already asked `isPayable()`),
 * and on 2026-09-11 it still read `balance > 0` and a three-status denylist. So on the invoice
 * LIST an invoice whose forgiven remainder was all that stood — partly written off, the rest then
 * paid — offered *Pay (demo)* on the forgiven amount and answered with a failure modal, while the
 * same invoice's own page correctly offered nothing. Two copies of one act with one fixed is the
 * `EditInvoice` shape recorded in CLAUDE.md, on the surface a tenant reads.
 *
 * Both modals also quoted the raw `balance` — the figure a write-off deliberately leaves standing
 * — where the amount the demo capture will actually take is `payableAmount()`.
 *
 * Composed as ONE spread (`...InvoiceActions::all()`) on both surfaces, never act by act, for the
 * reason `TenantActions` states: `Tests\Support\ActionStrips` expands a registry call to every
 * member it defines.
 *
 * Every closure takes `Invoice $record`: Filament injects the row on a table and the page's own
 * record on a `ViewRecord`, so one definition serves both.
 */
final class InvoiceActions
{
    /** @return array<int, Action> */
    public static function all(): array
    {
        return [
            self::payNow(),
            self::payDemo(),
        ];
    }

    /**
     * Only an admin TenantUser may pay, only a live gateway can take the money, and only an
     * invoice that may still receive a settlement is offered — `Invoice::isPayable()`, which asks
     * `App\Support\InvoiceSettlement` and nets prior write-offs out of the amount.
     *
     * Named once so `visible()` and the `abort_unless` in `action()` cannot drift: `visible()`
     * styles the page and does not gate dispatch.
     */
    public static function canPayNow(Invoice $record): bool
    {
        // `isPayable()` nets prior write-offs, so it is an aggregate unless the relation is
        // loaded. The table eager-loads it; a record page does not, and asks three or four times
        // per render.
        $record->loadMissing('writeOffs');

        return Portal::isAdmin()
            && config('integrations.paymob.enabled')
            && $record->isPayable();
    }

    /** The demo counterpart — whether the ENVIRONMENT permits the shortcut is `DemoPayments`' decision. */
    public static function canPayDemo(Invoice $record): bool
    {
        $record->loadMissing('writeOffs');

        return Portal::isAdmin()
            && DemoPayments::enabled()
            && $record->isPayable();
    }

    /** Open a live Paymob checkout for this invoice. */
    public static function payNow(): Action
    {
        return Action::make('payNow')
            ->label(__('admin.actions.pay_now'))
            ->icon('heroicon-o-credit-card')
            ->color('primary')
            ->visible(fn (Invoice $record): bool => self::canPayNow($record))
            ->authorize(fn (Invoice $record): bool => self::canPayNow($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Invoice $record): string => __('admin.actions.pay_now').' · '.$record->number)
            ->action(function (Invoice $record) {
                abort_unless(self::canPayNow($record), 403);

                try {
                    $session = app(PaymobPaymentInitiator::class)->start($record, Payment::CHANNEL_PORTAL);

                    return redirect()->away($session['iframe_url']);
                } catch (Throwable $e) {
                    Log::warning('Paymob Pay Now failed', [
                        'invoice_id' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                    Notification::make()
                        ->danger()
                        ->title(__('admin.notifications.pay_now_failed'))
                        ->body(__('admin.notifications.pay_now_failed_body'))
                        ->send();
                }
            });
    }

    /**
     * Demo payment — shown only while Paymob is disabled. Runs the real capture path (the same
     * one the mobile pay-demo endpoint uses): invoice → paid, payment created, tenant notified —
     * so the portal demonstrates the full post-payment flow without a live gateway.
     */
    public static function payDemo(): Action
    {
        return Action::make('payDemo')
            ->label(__('admin.actions.pay_now'))
            ->icon('heroicon-o-credit-card')
            ->color('primary')
            ->visible(fn (Invoice $record): bool => self::canPayDemo($record))
            ->authorize(fn (Invoice $record): bool => self::canPayDemo($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Invoice $record): string => __('admin.actions.pay_now').' · '.$record->number)
            // What the capture will TAKE — `payableAmount()`, net of write-offs — never the raw
            // balance a write-off leaves standing.
            ->modalDescription(fn (Invoice $record): string => __('admin.actions.pay_demo_modal_body', [
                'amount' => number_format($record->payableAmount(), 2),
            ]))
            ->modalSubmitActionLabel(__('admin.actions.pay_now'))
            ->action(function (Invoice $record): void {
                abort_unless(self::canPayDemo($record), 403);

                app(RecordDemoPaymentAction::class)->handle($record);

                // On the record page this IS the page's record, so the header and infolist read
                // the settled state on the render that follows.
                $record->refresh();

                Notification::make()
                    ->success()
                    ->title(__('admin.notifications.payment_received_title'))
                    ->body(__('admin.actions.pay_demo_success', ['number' => $record->number]))
                    ->send();
            });
    }
}
