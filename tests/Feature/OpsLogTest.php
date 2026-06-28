<?php

use App\Support\OpsLog;
use Illuminate\Support\Facades\Log;

it('routes operational events to the ops channel and redacts secrets/PII', function () {
    Log::shouldReceive('channel')->with('ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context) {
        expect($event)->toBe('eta.submission_failed');
        expect($context['invoice_id'])->toBe(7);            // kept
        expect($context['note'])->toBe('fine');             // kept
        expect($context['secret'])->toBe('[redacted]');     // redacted
        expect($context['api_key'])->toBe('[redacted]');    // redacted
        expect($context['meta']['hmac'])->toBe('[redacted]'); // nested redaction
        expect($context['meta']['ok'])->toBe('visible');     // nested kept

        return true;
    });

    OpsLog::error('eta.submission_failed', [
        'invoice_id' => 7,
        'note' => 'fine',
        'secret' => 'abc',
        'api_key' => 'live-key',
        'meta' => ['hmac' => 'xyz', 'ok' => 'visible'],
    ]);
});
