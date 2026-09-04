<?php

namespace App\Http\Controllers;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Http\Middleware\SetLocale;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\DemoPayments;
use App\Support\InvoiceSettlement;
use App\Support\IssuingEntity;
use App\Support\OpsLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
    /**
     * Resolve the invoice behind a pay token, or 404 (no enumeration).
     *
     * **A DRAFT is a 404, not a redirect.** It is not a document yet — nothing was issued, nothing
     * was posted, and the tenant has never been told it exists — so it has no public existence and
     * the honest public answer is that this URL resolves to nothing. Redirecting to the status page
     * instead would still print the tenant's NAME and the invoice number to whoever holds the link,
     * on a page whose whole vocabulary is about a payment; 404 is the same answer the cross-tenant
     * API gives, for the same reason.
     *
     * Only `draft` — the other relieved statuses (`cancelled`, `credited`, `written_off`) are real
     * documents the tenant received, and somebody holding that link deserves to be told what became
     * of them rather than shown a dead URL.
     */
    protected function resolve(string $token): Invoice
    {
        $invoice = Invoice::where('payment_link_token', $token)->firstOrFail();

        abort_if($invoice->status === 'draft', 404);

        return $invoice;
    }

    /** Public visitors have no session — pick the language from ?lang or the browser. */
    protected function locale(Request $request): string
    {
        // `SetLocale::SUPPORTED` is the ONE list. This page had its own copy, twice — and it is the
        // surface that can least afford to fall behind one: it is the only money screen with no
        // login in front of it, reached from the link on every invoice e-mail, so a language the
        // rest of the system had learned would simply never be offered to the person paying.
        $lang = $request->query('lang');
        if (in_array($lang, SetLocale::SUPPORTED, true)) {
            return $lang;
        }

        return $request->getPreferredLanguage(SetLocale::SUPPORTED) ?: (string) config('app.locale', 'en');
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
            // `writeOffs` because both `isPayable()` above and the blade's `payableAmount()`
            // net them — two aggregates per public page view without it.
            'invoice' => $invoice->loadMissing('tenant', 'items', 'asset', 'writeOffs'),
            'token' => $token,
            // The mall, not the software. A cardholder is about to enter card details on this page
            // and should recognise the merchant they are paying — "Atriom" is a name the tenant has
            // never seen on a lease or an invoice, and an unrecognised merchant is what a
            // chargeback starts as.
            ...IssuingEntity::forView($invoice->asset),
            'paymentEnabled' => (bool) config('integrations.paymob.enabled'),
            'applePayEnabled' => (bool) config('integrations.paymob.apple_pay_integration_id'),
            // Never both: DemoPayments::enabled() already requires the gateway to be off, so the
            // page can only ever offer one way to pay. A tenant shown both would take the free one.
            'demoEnabled' => DemoPayments::enabled(),
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

    /**
     * POST /pay/{token}/demo — settle the invoice without a gateway, for a box that has none.
     *
     * **This route has no login.** Every other demo-pay surface has an actor behind it — the
     * portal asks `Portal::isAdmin()`, the mobile endpoint runs under a Sanctum tenant token — and
     * this one has only the bearer token in the URL. That is a deliberate widening, made so a pay
     * link copied out of the admin panel can be clicked through end to end before Paymob exists.
     * Three things keep it contained:
     *
     *  - `DemoPayments::enabled()` is the same single predicate the other two ask, so the route is
     *    dead on production whatever the configuration says, dead unless `DEMO_PAYMENTS_ENABLED`
     *    is explicitly set outside local/testing, and dead the moment a real gateway is wired.
     *    It is checked BEFORE the token is resolved, so a disabled box answers a flat 404 and the
     *    endpoint is indistinguishable from one that was never built.
     *  - Its own tighter rate limit (see routes/web.php) rather than the /pay group's, because
     *    this one writes money and the others do not.
     *  - An `ops.log` line naming the invoice and the caller's IP. The other two paths can name a
     *    user; here the request is anonymous by construction, so the log is the ONLY record that a
     *    fabricated payment happened and where it came from.
     */
    public function demo(Request $request, string $token, RecordDemoPaymentAction $action): RedirectResponse
    {
        // Asked first, and independently of the token: when the shortcut is off this route must
        // look like it does not exist, not like a token that failed to match.
        abort_unless(DemoPayments::enabled(), 404);

        app()->setLocale($this->locale($request));
        $invoice = $this->resolve($token);

        // Nothing to collect (already paid, cancelled, credited, written off, or never issued) —
        // the status page says what happened. RecordDemoPaymentAction re-checks under a row lock
        // too; this is the friendly answer, that is the correct one.
        if (! $invoice->isPayable()) {
            return redirect()->route('pay.status', ['token' => $token]);
        }

        // CHANNEL_LINK, not null: status() finds the payment behind a link by channel, and a
        // null-channel capture would leave this page reporting a paid invoice for 0.00.
        $payment = $action->handle($invoice, Payment::CHANNEL_LINK);

        OpsLog::warning('invoice.demo_paid_via_link', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('pay.status', ['token' => $token]);
    }

    /** GET /pay/{token}/status — public result page (paid / failed / processing). */
    public function status(Request $request, string $token): View
    {
        app()->setLocale($this->locale($request));
        $invoice = $this->resolve($token)->loadMissing('tenant', 'writeOffs');

        $payment = $invoice->payments()
            ->where('channel', Payment::CHANNEL_LINK)
            ->orderByDesc('payments.id')
            ->first();

        // **`paid` must mean PAID.** Reading it off `payableAmount() <= 0` is wrong for every
        // RELIEVED status, because `settleableAmount()` returns 0 for all of them — so a
        // WRITTEN-OFF invoice rendered "✓ Payment successful — 0.00" on an unauthenticated page to
        // a tenant whose debt had just gone to bad debt, and `WriteOffInvoiceService::reverse()`
        // exists precisely because such a debt may still be chased. `closed` is the fourth state:
        // there is nothing to collect here and no claim that anybody paid it.
        $relieved = ! InvoiceSettlement::accepts($invoice);

        $state = match (true) {
            $relieved => 'closed',
            round((float) $invoice->collectableBalance(), 2) <= 0 => 'paid',
            $payment && $payment->status === 'failed' => 'failed',
            $payment && $payment->status === 'initiated' => 'processing',
            default => 'unpaid',
        };

        // Show the amount transacted on THIS link (what the client paid / owes),
        // not the full invoice total — the invoice may have been partly paid before. A `closed`
        // invoice shows nothing: there is no figure that is honest there, since the document was
        // cancelled, credited or forgiven rather than settled.
        $amount = match (true) {
            $state === 'closed' => null,
            $payment !== null => (float) $payment->amount,
            default => $invoice->payableAmount(),
        };

        return view('pay.status', [
            'invoice' => $invoice->loadMissing('asset'),
            'token' => $token,
            ...IssuingEntity::forView($invoice->asset),
            'state' => $state,
            'amount' => $amount,
            'appDeepLink' => config('integrations.app_deep_link'),
        ]);
    }
}
