<?php

namespace App\Notifications\Channels;

use App\Support\OpsLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use MailerSend\Exceptions\MailerSendException;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * The mail channel, with the transport made best-effort for a notification sent INLINE.
 *
 * **A notification's record must not depend on its transport.** Laravel sends an inline
 * notification's channels in `via()` order, sequentially, with no catch between them — and
 * `Notification::send()` walks its recipients the same way. So with `via() = ['mail', 'database']`
 * (nineteen of this app's thirty-five inline notifications) one transport failure erases the
 * in-app record for THAT recipient and never reaches the recipients behind it. The bell row IS the
 * record: it is what the portal and the mobile app show, and for every scan that de-duplicates on
 * it, it is also the idempotency stamp.
 *
 * Measured on the staging soak, 2026-09-11: `sales:scan-missing-declarations` ran on the 10th at
 * 08:00 with the mail transport answering `403 Forbidden`. Two tenants owed an August declaration.
 * The tenant's mail went first and threw, so the `database` row — the tenant's reminder, the
 * portal login's bell AND the stamp — was never written; the command caught it, `warn()`ed to
 * `/dev/null` under cron, and exited SUCCESS. Its next run is 10 October, which scans SEPTEMBER.
 * August's chase was gone for good, and the estimate that is scheduled "a week after the chase"
 * was about to land on tenants who had never been chased.
 *
 * Yardi, MRI and Entrata all keep the alert as a record and the e-mail as a delivery: a bounced
 * message never removes the alert from the dashboard and never blocks the event that raised it.
 * This is that rule at ONE seam — `ChannelManager::createMailDriver()` is
 * `$this->container->make(MailChannel::class)`, so binding this subclass covers every notification
 * the way `BellChannel` covers every bell — rather than a `via()` ordering convention across
 * nineteen files, which would still stop at the first recipient whose transport failed.
 *
 * **What is caught is what an ENVIRONMENT can do to a message, and nothing else** —
 * `TRANSPORT_FAILURES`. A fault inside `toMail()` (a `TypeError`, a missing view) is a bug and
 * stays loud. The list is four families because the driver does not normalise them: MailerSend's
 * transport re-wraps only its `MailerSendHttpException` into Symfony's `TransportException` (the
 * 403 on the box was exactly that), while a **422** — the trial plan's unique-recipient cap, an
 * unverified sender — is `MailerSendValidationException`, a sibling that is NOT re-wrapped, and a
 * DNS or connect failure surfaces as the PSR-18 client's exception beneath the driver. The review
 * of the first draft found that the one family it caught was the one the box had already shown it.
 *
 * **Three cases re-throw, and each is a statement about whose the failure is:**
 *
 *  1. **A QUEUED notification's mail is its own job.** Laravel dispatches one job per
 *     (recipient, channel) for a `ShouldQueue` notification, so its bell row already cannot go
 *     with its mail — and the job carries the retries (`tries=3` on the box) and lands in
 *     `failed_jobs`, which `atriom:health` watches and Discord hears about. Swallowing there would
 *     trade three retries and a red health row for one warning line.
 *  2. **A notification with no `database` channel has no record to protect.** A password-reset
 *     link is mail and nothing else; swallowing its failure would print "link sent" to someone
 *     who cannot log in, and those were the only inline mail paths whose failure reached the
 *     ERROR-line monitoring at all.
 *  3. **The caller INSISTS** — `requiringDelivery(fn () => …)`. The mail IS what a person asked
 *     for: an operator re-sending an invoice PDF to the tenant who says they never received it,
 *     where a bell row and a warning in a log they cannot see would let the screen say "sent"
 *     over a message that never left. It is the CALLER's statement and not the notification's,
 *     because the same `InvoiceIssuedNotification` goes out from the monthly billing run, which
 *     rightly carries on past a dead transport — one class, two contracts.
 *
 * The miss is logged to the OPS log at WARNING — not ERROR, because a dead mail token is an
 * environment condition and the daily soak check reads ERROR lines as product faults — under the
 * same `notification.delivery_failed` event `BestEffortNotification` writes, naming the
 * notification and the recipient, so the daily report counts them in one place. **No event is
 * dispatched for the miss**: `NotificationSender` listens for its own `NotificationFailed` and,
 * once it has heard one, suppresses the next genuine failure's event until a throw resets it —
 * so raising it here would cost a later, real one. What the sender sees instead is exactly what
 * it sees when `MailChannel` finds no address to send to: a `NotificationSent` with a null
 * response.
 *
 * What this softens: an inline transport failure used to be a caught `warn()` nobody read on
 * every scan, and an uncaught ERROR on the password resets (case 2 keeps those). It is one
 * ops-log line now, and the soak check puts a non-zero count in its verdict. A health row that
 * counts those lines over 24h is the production-grade follow-up (OPS-10).
 */
class BestEffortMailChannel extends MailChannel
{
    /**
     * The families a transport can throw — every Symfony transport (MailerSend's re-wrapped 403,
     * 429 and 5xx included); MailerSend's own, for the 422 it does not re-wrap; the PSR-18 client
     * beneath it, for DNS/connect/read failures; and a recipient address on the RECORD that no
     * message can be addressed to. That last one is data, not code — the operator fixes the
     * address, and the bell should be there when they do.
     *
     * @var list<class-string<Throwable>>
     */
    public const TRANSPORT_FAILURES = [
        TransportExceptionInterface::class,
        MailerSendException::class,
        ClientExceptionInterface::class,
        RfcComplianceException::class,
    ];

    /**
     * How many `requiringDelivery()` scopes are open. A static rather than a container scope on
     * purpose: it is a depth counter reset in `finally`, so a queue worker outliving the request
     * cannot inherit a stale value — the concern that rules statics out for MEMOISED data does not
     * apply to one that every scope closes.
     */
    private static int $requiringDelivery = 0;

    /**
     * Run `$send` with a transport failure re-thrown to the caller — case 3 in the class docblock.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    public static function requiringDelivery(callable $send): mixed
    {
        self::$requiringDelivery++;

        try {
            return $send();
        } finally {
            self::$requiringDelivery--;
        }
    }

    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $e) {
            if (! self::isTransportFailure($e) || self::failureIsTheCallers($notifiable, $notification)) {
                throw $e;
            }

            OpsLog::warning('notification.delivery_failed', [
                'channel' => 'mail',
                'notification' => class_basename($notification),
                'notifiable' => self::describe($notifiable),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function isTransportFailure(Throwable $e): bool
    {
        foreach (self::TRANSPORT_FAILURES as $family) {
            if ($e instanceof $family) {
                return true;
            }
        }

        return false;
    }

    /** The three re-throw cases in the class docblock, in that order. */
    private static function failureIsTheCallers(mixed $notifiable, Notification $notification): bool
    {
        return $notification instanceof ShouldQueue
            || ! in_array('database', (array) $notification->via($notifiable), true)
            || self::$requiringDelivery > 0;
    }

    /**
     * `Tenant#12`, `TenantUser#3`, or `AnonymousNotifiable` for a `Notification::route()` send —
     * enough to find the recipient again, never the address itself: `OpsLog::REDACT` covers
     * credentials and card data, not addresses, so the way to keep one out of ops.log is not to
     * write it.
     */
    private static function describe(mixed $notifiable): string
    {
        $key = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;

        return class_basename($notifiable).($key !== null && $key !== '' ? '#'.$key : '');
    }
}
