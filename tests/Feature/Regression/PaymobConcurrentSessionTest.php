<?php

/**
 * Two taps on "Pay" must not open two payment sessions for the same invoice.
 *
 * `PaymobPaymentInitiator::start()` is check-then-act: it looks for a reusable
 * session, and if it finds none it creates a Payment plus an allocation for the
 * FULL invoice balance. Nothing serialises the two halves.
 *
 * So two requests that arrive together — a double-click, two tabs, a retried
 * POST, an impatient client on a slow connection — both see "no session" and
 * both create one. The invoice then carries two `initiated` payments, each
 * allocated the whole balance. Neither moves the AR balance on its own
 * (recomputeTotals counts only captured allocations), but they are two live
 * Paymob orders against one debt: capture both and the tenant has paid twice,
 * and the invoice is over-allocated.
 *
 * The interleave is reproduced deterministically rather than with threads: the
 * fake gateway call re-enters start() at exactly the moment the real one is
 * waiting on the network — which is precisely the window a second request
 * lands in.
 */

use App\Models\Payment;
use App\Services\Paymob\PaymobClient;
use App\Services\Paymob\PaymobPaymentInitiator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'integrations.paymob.base_url' => 'https://sandbox.paymob.test',
        'integrations.paymob.api_key' => 'TEST-API-KEY',
        'integrations.paymob.integration_id' => '123456',
        'integrations.paymob.iframe_id' => '999',
        'integrations.paymob.currency' => 'EGP',
        'integrations.paymob.hmac_secret' => 'TEST-HMAC-SECRET',
    ]);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500]);
});

/**
 * A client whose session build re-enters start() once, simulating a second
 * request arriving while the first is still talking to the gateway.
 */
function reentrantPaymobClient(callable $reenter): PaymobClient
{
    return new class($reenter) extends PaymobClient
    {
        private bool $reentered = false;

        public function __construct(private $reenter)
        {
            parent::__construct('https://sandbox.paymob.test', 'k', '1', '9', 'EGP');
        }

        public function buildPaymentSession($invoice, ?int $integrationId = null): array
        {
            if (! $this->reentered) {
                $this->reentered = true;
                ($this->reenter)();
            }

            return [
                'order_id' => random_int(1000, 9999),
                'payment_token' => 'PAY-KEY',
                'iframe_url' => 'https://sandbox.paymob.test/iframe',
            ];
        }
    };
}

it('opens ONE session when two payment attempts race', function () {
    // 1s, not the 10s default: the re-entrant simulation is single-process, so
    // the inner attempt can never actually resolve — it is only here to prove
    // that the second one is BLOCKED rather than allowed to open a rival order.
    config(['integrations.paymob.session_lock_wait_seconds' => 1]);

    $initiator = null;
    $secondWasBlocked = false;

    $client = reentrantPaymobClient(function () use (&$initiator, &$secondWasBlocked) {
        try {
            // The second request, arriving mid-flight of the first.
            $initiator->start($this->invoice, Payment::CHANNEL_LINK);
        } catch (Throwable) {
            // Caught so the FIRST request still completes, exactly as it would
            // in production where the two are separate processes.
            $secondWasBlocked = true;
        }
    });

    $initiator = new PaymobPaymentInitiator($client);
    $initiator->start($this->invoice, Payment::CHANNEL_LINK);

    expect($secondWasBlocked)->toBeTrue('The second attempt was not serialised at all.');

    $initiated = Payment::where('status', 'initiated')
        ->whereHas('invoices', fn ($q) => $q->where('invoices.id', $this->invoice->id))
        ->get();

    expect($initiated)->toHaveCount(
        1,
        'Two concurrent attempts opened '.$initiated->count().' Paymob sessions for one invoice.'
    );
});

it('never allocates more than the invoice balance across concurrent attempts', function () {
    // The consequence that costs money: two live orders for one debt, each
    // allocated the full balance.
    config(['integrations.paymob.session_lock_wait_seconds' => 1]);

    $initiator = null;

    $client = reentrantPaymobClient(function () use (&$initiator) {
        try {
            $initiator->start($this->invoice, Payment::CHANNEL_LINK);
        } catch (Throwable) {
            // blocked, as intended
        }
    });

    $initiator = new PaymobPaymentInitiator($client);
    $initiator->start($this->invoice, Payment::CHANNEL_LINK);

    $allocated = (float) $this->invoice->payments()->sum('invoice_payment.allocated_amount');

    expect(round($allocated, 2))->toBeLessThanOrEqual(
        round((float) $this->invoice->total, 2),
        "Allocated {$allocated} against an invoice of {$this->invoice->total}."
    );
});

it('lets the second request REUSE the first session once it completes', function () {
    // Production behaviour, where the two attempts are separate processes: the
    // waiter does not fail, it inherits the session the first one opened. That
    // is what makes the lock a fix rather than merely a guard.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 4242]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    $initiator = app(PaymobPaymentInitiator::class);

    $first = $initiator->start($this->invoice, Payment::CHANNEL_LINK);
    $second = $initiator->start($this->invoice, Payment::CHANNEL_LINK);

    expect($second['reused'])->toBeTrue()
        ->and($second['payment_id'])->toBe($first['payment_id'])
        ->and($second['order_id'])->toBe($first['order_id']);

    expect(Payment::where('status', 'initiated')->count())->toBe(1);
});

it('releases the lock so the next payment attempt is not blocked forever', function () {
    // A lock held across a network call is a lock that can strand an invoice if
    // it is not released on every path.
    Http::fake([
        'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
        'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => 5151]),
        'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY-KEY']),
    ]);

    app(PaymobPaymentInitiator::class)->start($this->invoice, Payment::CHANNEL_LINK);

    $key = "paymob-session:{$this->invoice->id}:".Payment::CHANNEL_LINK;

    expect(Cache::lock($key, 5)->get())->toBeTrue('Lock was never released.');
});

it('releases the lock even when the gateway call throws', function () {
    // The path that stranded it: an exception between acquire and release.
    config(['integrations.paymob.session_lock_wait_seconds' => 1]);

    $client = new class extends PaymobClient
    {
        public function __construct()
        {
            parent::__construct('https://sandbox.paymob.test', 'k', '1', '9', 'EGP');
        }

        public function buildPaymentSession($invoice, ?int $integrationId = null): array
        {
            throw new RuntimeException('gateway down');
        }
    };

    try {
        (new PaymobPaymentInitiator($client))->start($this->invoice, Payment::CHANNEL_LINK);
    } catch (RuntimeException) {
        // expected
    }

    $key = "paymob-session:{$this->invoice->id}:".Payment::CHANNEL_LINK;

    expect(Cache::lock($key, 5)->get())
        ->toBeTrue('A failed gateway call left the invoice locked.');
});
