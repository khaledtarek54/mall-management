<?php

namespace App\Services\Paymob;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Glue between callers (Filament Pay-Now actions + the mobile API session
 * endpoint) and PaymobClient. Creates an 'initiated' Payment + allocates it
 * to the invoice (a no-op until the callback flips status to 'captured' —
 * see Invoice::recomputeTotals which only counts captured allocations) and
 * returns the session payload the caller can hand to the iframe / Paymob
 * SDK.
 *
 * Idempotent: if a recent 'initiated' Payment already exists for this
 * invoice, its stored session is reused instead of burning a fresh Paymob
 * order on every tap. The reuse window is shorter than the Paymob token
 * expiration (3600s) by a safety margin so we never serve a token that
 * will expire mid-checkout.
 *
 * Audit M11 F-42 / D-33.
 */
class PaymobPaymentInitiator
{
    /**
     * Reuse an existing initiated session if it was created less than this
     * many seconds ago. Below Paymob's 3600s payment_token TTL by enough
     * margin that the user has time to fill the card form.
     */
    public const REUSE_WINDOW_SECONDS = 2700;

    public function __construct(protected PaymobClient $client) {}

    /**
     * Returns the Paymob session for this invoice:
     *
     *   [
     *     'payment_token' => string,    // for the Paymob mobile SDK
     *     'iframe_url'    => string,    // for WebView / browser redirect
     *     'order_id'      => int,       // Paymob's order id
     *     'payment_id'    => int,       // our Payment row id (poll target)
     *     'expires_at'    => CarbonImmutable, // session-token expiration
     *     'reused'        => bool,      // true if returned from cache
     *   ]
     *
     * The 'initiated' Payment row is keyed by Paymob's order_id so the S2S
     * callback can recover it without extra state.
     *
     * @return array{payment_token:string,iframe_url:string,order_id:int,payment_id:int,expires_at:CarbonImmutable,reused:bool}
     */
    public function start(Invoice $invoice, string $channel = Payment::CHANNEL_MOBILE, ?int $integrationId = null): array
    {
        // Serialised per invoice+channel. Without this, start() is check-then-act
        // with a NETWORK CALL in the middle: two requests arriving together — a
        // double-click, two tabs, a retried POST — both find no reusable session
        // and both open one, leaving two live Paymob orders against one debt,
        // each allocated the full balance. Capture both and the tenant has paid
        // twice. Proven in PaymobConcurrentSessionTest.
        //
        // The lock is held ACROSS the gateway call deliberately: releasing before
        // it would reopen the exact window being closed. Hence the TTL, so a
        // wedged request cannot hold it forever.
        $lock = Cache::lock(
            "paymob-session:{$invoice->id}:{$channel}",
            (int) config('integrations.paymob.session_lock_seconds', 30),
        );

        // Waits for the in-flight request rather than failing outright, so the
        // second tap ends up REUSING the first one's session below.
        $lock->block((int) config('integrations.paymob.session_lock_wait_seconds', 10));

        try {
            return $this->startLocked($invoice, $channel, $integrationId);
        } finally {
            $lock->release();
        }
    }

    /**
     * The session build, guaranteed to run one-at-a-time per invoice+channel.
     *
     * @return array{payment_token:string,iframe_url:string,order_id:int,payment_id:int,expires_at:CarbonImmutable,reused:bool}
     */
    protected function startLocked(Invoice $invoice, string $channel, ?int $integrationId): array
    {
        // Re-checked INSIDE the lock — the point of the whole exercise. The
        // request that waited now sees the session the first one created and
        // reuses it instead of opening a second.
        //
        // The card flow reuses a recent session; the Apple Pay flow (its own
        // integration) always builds fresh so a card token is never served for it.
        if ($integrationId === null && $reused = $this->findReusableSession($invoice, $channel)) {
            OpsLog::info('paymob.session_reused', [
                'invoice' => $invoice->id,
                'payment' => $reused['payment_id'],
                'order' => $reused['order_id'],
                'channel' => $channel,
            ]);

            return $reused;
        }

        $session = $this->client->buildPaymentSession($invoice, $integrationId);

        return DB::transaction(function () use ($invoice, $session, $channel, $integrationId) {
            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                // `payableAmount()`, never the raw balance: a partial write-off leaves `balance`
                // standing by design, so charging it asks the tenant for money the operator has
                // already forgiven. See Invoice::payableAmount().
                'amount' => $invoice->payableAmount(),
                'currency' => $invoice->currency ?? 'EGP',
                'method' => 'card',
                'status' => 'initiated',
                'payment_date' => now(),
                'gateway' => 'paymob',
                'channel' => $channel,
                // **Stated here, because a receipt is the one money document that cannot work it
                // out for itself.** `RecordsBankAccount` resolves the property as
                // `asset_id ?? bill?->asset_id ?? TenantScope::currentAssetId()`, and `payments`
                // has NO `asset_id` column — a receipt's books dimension comes from the invoices it
                // settles, which do not exist yet at `creating`. There is no Filament tenant on a
                // gateway request, so the fallback answers null and the receipt fell to the generic
                // `bank` POSTING ROLE: the state `MatchBankStatementLineService::candidatesFor()`
                // cannot reconcile, since it finds candidates BY the chart account and would offer
                // one named bank's postings alongside everything nobody attributed. On the online
                // card channel that is most of the inbound money on a live install (SW-228).
                //
                // The invoice knows the mall, so this is a derivation and not a guess — and `card`
                // is a rail `PaymentMethod::requiresBankAccount()` answers TRUE for.
                'bank_account_id' => Payment::defaultBankAccountIdFor($invoice->asset_id, 'card'),
                'gateway_transaction_id' => self::orderRef($session['order_id']),
                'gateway_response' => [
                    'order_id' => $session['order_id'],
                    'payment_token' => $session['payment_token'],
                    'iframe_url' => $session['iframe_url'],
                    'issued_at' => now()->toIso8601String(),
                ],
            ]);

            // Allocate the whole payable amount upfront. Invoice::recomputeTotals
            // filters by payments.status = 'captured', so this allocation has
            // zero effect on the AR balance until the callback marks the
            // Payment captured.
            $payment->invoices()->attach($invoice->id, [
                'allocated_amount' => $invoice->payableAmount(),
            ]);

            // The public revenue surface had NO logging at all: when a tenant reported "the pay
            // button did nothing", the only trace was a PaymobClient warning if the HTTP call
            // itself failed. This is the line that ties a Paymob order id to our Payment row, so
            // a support question can be answered from ops.log instead of a database dig.
            // Neither the token nor the iframe URL: a payment_token authorises a charge. (It IS
            // in OpsLog::REDACT now — but only because writing this comment revealed it was not,
            // since that list matches keys exactly and held `token`, never `payment_token`.)
            OpsLog::info('paymob.session_started', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'tenant_id' => $invoice->tenant_id,
                'payment_id' => $payment->id,
                'order_id' => (int) $session['order_id'],
                'amount' => $invoice->payableAmount(),
                'channel' => $channel,
                // Apple Pay runs its own integration; which one was used decides where a failed
                // charge is investigated.
                'integration_id' => $integrationId,
            ]);

            return [
                'payment_token' => $session['payment_token'],
                'iframe_url' => $session['iframe_url'],
                'order_id' => (int) $session['order_id'],
                'payment_id' => (int) $payment->id,
                'expires_at' => Carbon::now()->addSeconds(PaymobClient::PAYMENT_TOKEN_TTL_SECONDS)->toImmutable(),
                'reused' => false,
            ];
        });
    }

    /**
     * Convention for the gateway_transaction_id we stash on initiated rows so
     * the callback can recover the Payment by Paymob's order id. After capture
     * the controller appends the transaction id (see CallbackController).
     */
    public static function orderRef(int $orderId): string
    {
        return "paymob:order:{$orderId}";
    }

    /**
     * Look for an 'initiated' Payment for this invoice whose stored Paymob
     * session is still within the reuse window. Returns the same shape as
     * start() with reused=true, or null if no usable session exists.
     */
    protected function findReusableSession(Invoice $invoice, string $channel = Payment::CHANNEL_MOBILE): ?array
    {
        // Scope reuse to the SAME channel so a mobile session is never reused for
        // a payment-link payment (their return handling + reporting differ).
        $payment = Payment::query()
            ->where('gateway', 'paymob')
            ->where('status', 'initiated')
            ->where('channel', $channel)
            ->where('tenant_id', $invoice->tenant_id)
            ->whereHas('invoices', fn ($q) => $q->where('invoices.id', $invoice->id))
            ->where('created_at', '>=', now()->subSeconds(self::REUSE_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if (! $payment) {
            return null;
        }

        // Only reuse a session whose amount still matches what's owed. If a credit, a partial
        // payment or a WRITE-OFF reduced what is collectable since the session was created, the
        // gateway token is bound to the OLD (higher) amount — reusing it would overcharge. Fall
        // through to a fresh session. Compared against `payableAmount()` and not the raw balance,
        // or every session for a partly written-off invoice is discarded as stale for ever.
        if (round((float) $payment->amount, 2) !== $invoice->payableAmount()) {
            // Worth a line: this is the branch that stops a tenant being charged an amount a
            // credit or part-payment has already reduced. Silent, it looks identical to "the
            // reuse window expired" — and the two have very different implications if the
            // amounts ever disagree for a reason we did not predict.
            OpsLog::info('paymob.session_discarded_stale_amount', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'session_amount' => round((float) $payment->amount, 2),
                'invoice_payable' => $invoice->payableAmount(),
            ]);

            return null;
        }

        $stored = (array) $payment->gateway_response;
        if (empty($stored['iframe_url']) || empty($stored['payment_token']) || empty($stored['order_id'])) {
            return null;
        }

        return [
            'payment_token' => (string) $stored['payment_token'],
            'iframe_url' => (string) $stored['iframe_url'],
            'order_id' => (int) $stored['order_id'],
            'payment_id' => (int) $payment->id,
            'expires_at' => Carbon::parse($stored['issued_at'] ?? $payment->created_at)
                ->addSeconds(PaymobClient::PAYMENT_TOKEN_TTL_SECONDS)
                ->toImmutable(),
            'reused' => true,
        ];
    }
}
