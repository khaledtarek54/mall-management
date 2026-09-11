<?php

use App\Support\Health;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Regression — OPS-10. A notification its transport dropped shows on `atriom:health`.
 *
 * Since SW-252 an inline mail failure is one ops-log WARNING (`notification.delivery_failed`) and
 * no longer a failed job — right for the record, wrong for the monitoring: on production a dead
 * mail token would show ONLY in that file, and `atriom:notify-status` (Discord) watches Health
 * rows. `Health::checkNotificationDelivery()` counts the event over the last 24h off the daily ops
 * files — the durable record everything already writes to, not a Redis key a flush zeroes — and
 * `OpsLog::countEventSince()` is the reader, proved here on real files in a temporary directory
 * the ops channel is pointed at for the length of each case.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/atriom-ops-'.uniqid();
    mkdir($this->dir);
    config([
        'logging.channels.ops_daily.path' => $this->dir.'/ops.log',
        'logging.channels.ops_daily.level' => 'debug',
        'logging.channels.ops.channels' => ['ops_daily'],
    ]);
    // `Log::channel()` memoises drivers, so the channel must be rebuilt to see the temp path.
    Log::forgetChannel('ops');
    Log::forgetChannel('ops_daily');
    CarbonImmutable::setTestNow('2026-09-11 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Log::forgetChannel('ops');
    Log::forgetChannel('ops_daily');
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($this->dir);
});

/** A line the way the daily driver writes one. */
function opsLine(string $at, string $level, string $event, array $context): string
{
    return "[{$at}] staging.{$level}: {$event} ".json_encode($context)."\n";
}

it('counts the event inside the window, across yesterday\'s file and today\'s, and nothing else', function () {
    file_put_contents($this->dir.'/ops-2026-09-10.log',
        opsLine('2026-09-10 11:59:00', 'WARNING', 'notification.delivery_failed', ['notification' => 'Old'])      // 24h+1min ago — outside
        .opsLine('2026-09-10 12:00:00', 'WARNING', 'notification.delivery_failed', ['notification' => 'Edge'])    // exactly 24h — inside
        .opsLine('2026-09-10 15:00:00', 'WARNING', 'notification.delivery_failed_later', ['notification' => 'X'])  // a different event
        .opsLine('2026-09-10 16:00:00', 'INFO', 'sales.estimate_run', ['raised' => 0]));
    file_put_contents($this->dir.'/ops-2026-09-11.log',
        opsLine('2026-09-11 08:05:00', 'WARNING', 'notification.delivery_failed', ['notification' => 'InvoiceIssued', 'notifiable' => 'tenant#4', 'error' => '403 Forbidden'])
        .opsLine('2026-09-11 08:06:00', 'WARNING', 'notification.delivery_failed', ['notification' => 'Latest', 'notifiable' => 'tenant#5', 'error' => '403 Forbidden']));

    $read = OpsLog::countEventSince('notification.delivery_failed', now()->subDay());

    expect($read['count'])->toBe(3)
        ->and(json_decode($read['last'], true)['notification'])->toBe('Latest');
});

it('reads back what OpsLog actually writes — the writer/reader contract, through the real channel', function () {
    // The other cases compose lines by hand, which proves the reader against a FIXTURE's idea of
    // the format. This one pins Laravel's own: `LineFormatter(null, 'Y-m-d H:i:s', …)` with
    // `allowInlineLineBreaks`, so an error carrying a newline — an SMTP 535 is several lines — is
    // written across physical lines that do not start with `[`, and the reader must fold them back
    // or the last miss decodes to nothing (the review measured `Last: ? to ? ()`).
    OpsLog::warning('notification.delivery_failed', [
        'channel' => 'mail', 'notification' => 'InvoiceIssuedNotification', 'notifiable' => 'Tenant#4',
        'exception' => 'Symfony\\Component\\Mailer\\Exception\\UnexpectedResponseException',
        'error' => "535-5.7.8 Username and Password not accepted.\r\n535 5.7.8 https://support.example/answer",
    ]);
    OpsLog::info('sales.estimate_run', ['raised' => 0]);

    $read = OpsLog::countEventSince('notification.delivery_failed', now()->subDay());
    $last = json_decode((string) $read['last'], true);

    expect(glob($this->dir.'/ops-*.log'))->toHaveCount(1)
        ->and($read['count'])->toBe(1)
        ->and($last)->not->toBeNull()
        ->and($last['notification'])->toBe('InvoiceIssuedNotification')
        ->and($last['error'])->toContain('535 5.7.8');
});

it('reads zero off no files at all — a missing day is a quiet day, not an error', function () {
    expect(OpsLog::countEventSince('notification.delivery_failed', now()->subDay()))
        ->toBe(['count' => 0, 'last' => null]);
});

it('goes red on a deployed box while the transport is dropping mail, naming the last miss', function () {
    inEnvironment('staging');
    file_put_contents($this->dir.'/ops-2026-09-11.log',
        opsLine('2026-09-11 08:05:00', 'WARNING', 'notification.delivery_failed', ['channel' => 'mail', 'notification' => 'SalesDeclarationReminderNotification', 'notifiable' => 'Tenant#4 Carrefour Express', 'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException', 'error' => 'Unable to send an email: [status code] 403 Forbidden']));

    $row = Health::run()['checks']['notification_delivery'];

    expect($row['ok'])->toBeFalse()
        ->and($row['detail'])->toContain('1 notification(s) dropped')
        ->and($row['detail'])->toContain('SalesDeclarationReminderNotification to Tenant#4 Carrefour Express')
        ->and($row['detail'])->toContain('403 Forbidden')
        ->and($row['detail'])->toContain('MAIL_MAILER');
});

it('refuses to call a file it cannot see clean — ops_daily out of the stack, or warnings dropped', function () {
    // "Examined nothing" is never a pass. With either setting the writer never reaches the file
    // and a count of zero is a statement about nothing; the first cut answered "no delivery
    // failures in the last 24h" one line after a real one (review, 2026-09-11).
    inEnvironment('staging');

    config(['logging.channels.ops.channels' => ['slack']]);
    $row = Health::run()['checks']['notification_delivery'];
    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain('cannot see delivery failures')->and($row['detail'])->toContain('OPS_LOG_STACK');

    config(['logging.channels.ops.channels' => ['ops_daily'], 'logging.channels.ops_daily.level' => 'error']);
    $row = Health::run()['checks']['notification_delivery'];
    expect($row['ok'])->toBeFalse()->and($row['detail'])->toContain('OPS_LOG_LEVEL=error');
});

it('blames the record, not the transport, when the address is what failed', function () {
    inEnvironment('staging');
    file_put_contents($this->dir.'/ops-2026-09-11.log',
        opsLine('2026-09-11 08:05:00', 'WARNING', 'notification.delivery_failed', ['channel' => 'mail', 'notification' => 'InvoiceIssuedNotification', 'notifiable' => 'Tenant#9', 'exception' => 'Symfony\\Component\\Mime\\Exception\\RfcComplianceException', 'error' => 'Email "x@@y" does not comply with addr-spec of RFC 2822.']));

    $row = Health::run()['checks']['notification_delivery'];

    expect($row['ok'])->toBeFalse()
        ->and($row['detail'])->toContain('RfcComplianceException')
        ->and($row['detail'])->toContain('fix the address')
        ->and($row['detail'])->not->toContain('MAIL_MAILER');
});

it('is green on a deployed box with a clean day — the control', function () {
    inEnvironment('staging');
    file_put_contents($this->dir.'/ops-2026-09-11.log',
        opsLine('2026-09-11 08:05:00', 'INFO', 'sales.estimate_run', ['raised' => 0]));

    $row = Health::run()['checks']['notification_delivery'];

    expect($row['ok'])->toBeTrue()
        ->and($row['detail'])->toBe('no delivery failures in the last 24h');
});

it('does not run on a workstation', function () {
    file_put_contents($this->dir.'/ops-2026-09-11.log',
        opsLine('2026-09-11 08:05:00', 'WARNING', 'notification.delivery_failed', ['notification' => 'X']));

    expect(Health::run()['checks']['notification_delivery'])
        ->toBe(['ok' => true, 'detail' => 'not checked on a workstation']);
});
