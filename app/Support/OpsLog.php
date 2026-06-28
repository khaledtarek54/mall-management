<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

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
    /** Context keys whose values are always redacted (secrets / card data / credentials). */
    private const REDACT = [
        'password', 'token', 'api_key', 'apikey', 'hmac', 'secret',
        'pan', 'card', 'card_number', 'cvv', 'authorization', 'auth_token',
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

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function scrub(array $context): array
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
}
