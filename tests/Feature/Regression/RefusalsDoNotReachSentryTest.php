<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A refusal is not a fault, and must not be reported as one
|--------------------------------------------------------------------------
| `DomainException` is this app's refusal mechanism — 139 throw sites in app/, every one the
| system correctly declining something the operator asked for. bootstrap/app.php has said so in
| prose since the web refusal path was written.
|
| Laravel's internal don't-report list covers ValidationException and friends but NOT
| DomainException, which is a plain SPL class. So the moment a Sentry DSN is set, every refusal in
| the system would arrive as an error — and the failures nobody anticipated, which are the entire
| reason Sentry is there, would be buried under hundreds of the system working as designed.
|
| Found on 2026-08-30, before the DSN was set rather than after.
*/

use Illuminate\Contracts\Debug\ExceptionHandler;

it('does not report a refusal', function () {
    expect(app(ExceptionHandler::class)->shouldReport(new DomainException('That period is closed.')))
        ->toBeFalse();
});

it('still reports a genuine fault', function () {
    // The control. Without it, a handler that reported nothing at all would satisfy the test
    // above and read as a pass — which would silently switch error reporting off entirely.
    expect(app(ExceptionHandler::class)->shouldReport(new RuntimeException('Disk is full.')))
        ->toBeTrue();
});
