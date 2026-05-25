<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

file_put_contents('/tmp/cov-debug.log', date('H:i:s') . " bootstrap-loaded pid=" . getmypid() . " cov=" . (getenv('COVERAGE') ?: 'unset') . "\n", FILE_APPEND);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // The API does not use cookies/session — Sanctum tokens only. Disable
        // CSRF for /api/* (Laravel does this by default but spell it out).
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // E2E coverage capture — only active when the server is booted with
        // COVERAGE=1. See app/Http/Middleware/RecordCoverage.php.
        @file_put_contents('/tmp/cov-debug.log', date('H:i:s') . " bootstrap shouldRecord=" . (\App\Http\Middleware\RecordCoverage::shouldRecord() ? 'yes' : 'no') . "\n", FILE_APPEND);
        if (\App\Http\Middleware\RecordCoverage::shouldRecord()) {
            $middleware->prepend(\App\Http\Middleware\RecordCoverage::class);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
