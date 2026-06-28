<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\OpsLog;

/**
 * The PUBLIC online payment link — no login. A client opens /pay/{token},
 * reviews the invoice, pays via Paymob, and lands on a public status page.
 *
 * This is the `payment_link` channel — deliberately separate from the in-app
 * (mobile / portal) flows: it has its own initiation, its own public result
 * page, and its sessions are never reused across channels. The shared S2S
 * callback (CallbackController) captures regardless of channel; the browser
 * return is routed back here by channel.
 */
class PaymentLinkController
{
    /** Resolve the invoice behind a pay token, or 404 (no enumeration). */
    protected function resolve(string $token): Invoice
    {
        return Invoice::where('payment_link_token', $token)->firstOrFail();
    }

    /** Public visitors have no session — pick the language from ?lang or the browser. */
    protected function locale(Request $request): string
    {
        $lang = $request->query('lang');
        if (in_array($lang, ['en', 'ar'], true)) {
            return $lang;
        }

        return $request->getPreferredLanguage(['en', 'ar']) ?: (string) config('app.locale', 'en');
    }

    /** GET /pay/{token} — invoice summary + Pay button. */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        app()->setLocale($this->locale($request));
        $invoice = $this->resolve($token);

        // Nothing to collect → show the result instead of an empty pay form.
        if (! $invoice->isPayable()) {
            return redirect()->route('pay.status', ['token' => $token]);
        }

        return view('pay.show', [
            'invoice' => $invoice->loadMissing('tenant', 'items'),
            'token' => $token,
            'paymentEnabled' => (bool) config('integrations.paymob.enabled'),
            'applePayEnabled' => (bool) config('integrations.paymob.apple_pay_integration_id'),
        ]);
    }

    /** POST /pay/{token}/start — open a Paymob session and hand off to the gateway. */
    public function start(Request $request, string $token, PaymobPaymentInitiator $initiator): RedirectResponse
    {
        app()->setLocale($this->locale($request));
        $invoice = $this->resolve($token);

        if (! config('integrations.paymob.enabled') || ! $invoice->isPayable()) {
            return redirect()->route('pay.status', ['token' => $token]);
        }

        // Apple Pay uses its own Paymob integration when configured; else card.
        $applePayId = config('integrations.paymob.apple_pay_integration_id');
        $integrationId = ($request->input('method') === 'apple_pay' && $applePayId) ? (int) $applePayId : null;

        try {
            $session = $initiator->start($invoice, Payment::CHANNEL_LINK, $integrationId);
        } catch (\Throwable $e) {
            OpsLog::warning('Payment-link session failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return redirect()->route('pay.show', ['token' => $token])
                ->with('error', __('admin.notifications.payment_return_failed'));
        }

        return redirect()->away($session['iframe_url']);
    }

    /** GET /pay/{token}/status — public result page (paid / failed / processing). */
    public function status(Request $request, string $token): View
    {
        app()->setLocale($this->locale($request));
        $invoice = $this->resolve($token)->loadMissing('tenant');

        $payment = $invoice->payments()
            ->where('channel', Payment::CHANNEL_LINK)
            ->orderByDesc('payments.id')
            ->first();

        $state = match (true) {
            round((float) $invoice->balance, 2) <= 0 => 'paid',
            $payment && $payment->status === 'failed' => 'failed',
            $payment && $payment->status === 'initiated' => 'processing',
            default => 'unpaid',
        };

        // Show the amount transacted on THIS link (what the client paid / owes),
        // not the full invoice total — the invoice may have been partly paid before.
        $amount = $payment !== null ? (float) $payment->amount : (float) $invoice->balance;

        return view('pay.status', [
            'invoice' => $invoice,
            'token' => $token,
            'state' => $state,
            'amount' => $amount,
            'appDeepLink' => config('integrations.app_deep_link'),
        ]);
    }
}
