<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Deliver a notification without letting a DELIVERY failure end the run that raised it.
 *
 * **A scheduled scan's product is the FINDING; the message is only how that finding travels.** When
 * one statement does both, an outage on the transport erases the finding as well — and that is the
 * worst possible direction for exactly the scans whose whole result is *the absence of a row*.
 *
 * Measured on the staging soak, 2026-09-10, on `pdc:scan-coverage` — the scan that reports which
 * tenants are about to run OUT of lodged cheques. **35 of this app's 38 notifications send inline**
 * (queued is the exception, not the rule), the mail transport answered `403 Forbidden`, and the
 * exception unwound the entire command: the remaining leases were never notified, the scheduler
 * logged an error naming nothing anybody could act on, and — because the service recorded its
 * finding AFTER the delivery loop — `OpsLog` never wrote `pdc.coverage_ending` at all. Every
 * Monday, silently, with the daily report showing nothing missing because nothing had happened.
 *
 * `MonthlyBillingService::notifyInvoiceIssued()` is the precedent this follows: catch, log, carry on.
 *
 * **WARNING and not ERROR, deliberately.** A transport outage is an environment condition, and the
 * daily soak check greps the application log for ERROR lines — raising one here would make a broken
 * mail token look like a product fault every morning, which is how a red line stops being read.
 * The ops log is where it lands, and the daily report reads that too.
 *
 * **PRECONDITION, and it is the thing to check before reusing this.** Swallowing a delivery
 * failure is only safe where the next run will REPORT THE SAME THING AGAIN. Both call sites qualify:
 * neither writes an idempotency stamp, so tomorrow's scan re-reads the same leases and permits. A
 * scan that stamps a row to say "alerted" — `requests:scan-sla-breaches`, `facility:scan-sla-breaches`
 * and the four expiry scans all do — must NOT use this as-is: the stamp would stand while the alert
 * was silently dropped, and the breach would then never be raised again. There, either stamp AFTER a
 * confirmed send or let the failure be loud.
 *
 * **It catches `Throwable`, which is broader than the transport**, so a genuine bug inside a
 * notification degrades to a warning rather than a crash. That is the deliberate trade: the
 * alternative — enumerating the exception types six mail/SMS/push drivers can raise — fails in the
 * direction this class exists to prevent. The exception CLASS is logged so a `TypeError` reads
 * differently from a 403.
 */
final class BestEffortNotification
{
    /**
     * @param  Collection<int, mixed>|array<int, mixed>|mixed  $recipients
     * @param  array<string, mixed>  $context  what the caller was doing — the scan, the record
     * @return bool whether it was delivered; callers that only report may ignore it
     */
    public static function send(mixed $recipients, mixed $notification, array $context = []): bool
    {
        try {
            Notification::send($recipients, $notification);

            return true;
        } catch (Throwable $e) {
            OpsLog::warning('notification.delivery_failed', $context + [
                'notification' => class_basename($notification),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
