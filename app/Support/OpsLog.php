<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Sentry\Event;

/**
 * Operational logging for the money + integration paths (Paymob, ETA, billing,
 * CAM, late fees, payment links).
 *
 * This is NOT the audit trail — model "who changed what" lives in the DB via the
 * spatie LogsActivity trait. OpsLog is diagnostic logging to a dedicated `ops`
 * log channel so outages, gateway/tax rejections, and batch-run summaries are:
 *   - easy to find (their own retained ops.log, not buried in app noise),
 *   - alertable (point OPS_LOG_STACK at slack in production),
 *   - consistent (every call routes through here),
 *   - safe (secrets/card data/PII are scrubbed centrally — see REDACT).
 *
 * Usage: OpsLog::warning('paymob.request_failed', ['step' => 'auth', 'status' => 502]);
 * Event names are dot-namespaced: "<area>.<event>" (paymob.*, eta.*, billing.*, cam.*).
 */
class OpsLog
{
    /**
     * Context keys whose values are always redacted (secrets / card data / credentials).
     *
     * **Matching is EXACT, not substring** — `scrub()` does `in_array(strtolower($key), …)`. So
     * `token` here does NOT cover `payment_token`, and every variant has to be spelled out. That
     * is not obvious from reading the list, and it is the way this leaks: someone logs
     * `['payment_token' => …]`, sees `token` in REDACT, and reasonably assumes it is covered.
     *
     * `payment_token` is a Paymob bearer credential that AUTHORISES A CHARGE, and
     * `payment_key`/`access_token` are the same class of thing.
     */
    private const REDACT = [
        'password', 'token', 'api_key', 'apikey', 'hmac', 'secret',
        'pan', 'card', 'card_number', 'cvv', 'authorization', 'auth_token',
        'payment_token', 'payment_key', 'access_token', 'refresh_token', 'bearer',
    ];

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    private static function write(string $level, string $event, array $context): void
    {
        Log::channel('ops')->{$level}($event, self::scrub($context));
    }

    /**
     * How many times `$event` was written since `$since` — read back off the daily files.
     *
     * The ops log is the durable record of what the scans found and what the transports dropped
     * (OPS-10): a file, rotated daily and kept `OPS_LOG_DAYS`, that a Redis flush cannot zero and
     * that the daily soak check already reads. A counter kept anywhere else would be a second
     * record of the same events beside the one everything writes to. Only the files the window can
     * touch are opened (one per day it spans), and only events at or after `$since` are counted.
     *
     * An event STARTS a line — `[Y-m-d H:i:s] env.LEVEL: event {json}` — and may not end one:
     * Laravel builds the daily driver's `LineFormatter` with `allowInlineLineBreaks`, so a context
     * value carrying a newline (an SMTP 535 is several lines; so is a `TypeError`) is written across
     * as many physical lines, none of which starts with `[`. Continuation lines are folded back
     * into the event, or the last miss the health row names decodes to nothing (review, 2026-09-11).
     *
     * `$since` must be an app-timezone Carbon — the file is written in `app.timezone` and the
     * floor is compared as text; a UTC `$since` shifts the window by the offset.
     *
     * @return array{count: int, last: string|null} the count, and the last event's context (JSON)
     */
    public static function countEventSince(string $event, CarbonInterface $since): array
    {
        $path = (string) config('logging.channels.ops_daily.path', storage_path('logs/ops.log'));
        $dir = dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);

        $count = 0;
        $last = null;
        $needle = ': '.$event.' ';
        $floor = $since->format('Y-m-d H:i:s');

        for ($day = $since->copy()->startOfDay(); $day->lte(now()); $day = $day->addDay()) {
            $file = "{$dir}/{$stem}-{$day->format('Y-m-d')}.log";

            if (! is_file($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            $collecting = false;

            while (($line = fgets($handle)) !== false) {
                $startsEvent = strlen($line) >= 21 && $line[0] === '[' && $line[20] === ']';

                if (! $startsEvent) {
                    // A continuation of the event being collected, or of one being ignored. Joined
                    // with the two-character escape, not a real newline: the formatter UNESCAPED
                    // these out of a JSON string, and re-escaping is what makes the context decode.
                    if ($collecting) {
                        $last = rtrim((string) $last).'\\n'.rtrim($line, "\r\n");
                    }

                    continue;
                }

                $collecting = false;

                if (! str_contains($line, $needle) || substr($line, 1, 19) < $floor) {
                    continue;
                }

                $count++;
                $last = trim((string) substr($line, strpos($line, $needle) + strlen($needle)));
                $collecting = true;
            }

            fclose($handle);
        }

        return ['count' => $count, 'last' => $last];
    }

    /**
     * Whether a WARNING written through `OpsLog` reaches the daily file the readers above open.
     *
     * Two ordinary settings make it not: `OPS_LOG_STACK` without `ops_daily` (the stack is what
     * `OpsLog` writes to; the file is what `countEventSince()` reads), and `OPS_LOG_LEVEL` above
     * warning (the handler drops the line). Either turns a count of zero into a statement about
     * nothing, so a reader must ask this first and say "cannot see" rather than "none".
     */
    public static function warningsReachTheDailyFile(): ?string
    {
        $stack = (array) config('logging.channels.ops.channels', []);

        if (! in_array('ops_daily', $stack, true)) {
            return 'ops_daily is not in OPS_LOG_STACK ('.implode(',', $stack).')';
        }

        $level = strtolower((string) config('logging.channels.ops_daily.level', 'debug'));

        if (! in_array($level, ['debug', 'info', 'notice', 'warning'], true)) {
            return "OPS_LOG_LEVEL={$level} drops warnings";
        }

        return null;
    }

    /**
     * Redact secrets/card data from an arbitrary context array, recursively.
     *
     * Public because it is the project's ONE redaction policy and has a second consumer:
     * `config/sentry.php`'s `before_send` runs every outbound error event's `extra` through
     * it, so a key that is unsafe in ops.log is equally unsafe leaving the building. Keep it
     * that way — a second list would drift, and the copy that drifts is the one that leaks.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::scrub($value);
            }
        }

        return $context;
    }

    /**
     * Sentry's `before_send` hook — the last line of defence before an event leaves the building.
     *
     * `send_default_pii => false` already withholds request/cookie/user data, but anything a
     * developer hands Sentry deliberately (`extra`, breadcrumb context) is still sent. This runs it
     * through {@see scrub()}, the SAME redaction list that protects ops.log, so a key that is unsafe
     * to write to disk is equally unsafe to transmit.
     *
     * **It lives here as a static method rather than a closure in `config/sentry.php`, and that is
     * load-bearing.** `php artisan config:cache` serialises the whole config tree with
     * `var_export()`, which cannot represent a closure — so a closure anywhere in config makes the
     * command throw, and config caching is a step in every production deploy. A `[Class, 'method']`
     * array is both a valid PHP callable and plain `var_export`-able data, so the hook survives
     * caching instead of blocking it. Any future config hook belongs here for the same reason.
     */
    public static function scrubSentryEvent(Event $event): ?Event
    {
        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(self::scrub($extra));
        }

        return $event;
    }
}
