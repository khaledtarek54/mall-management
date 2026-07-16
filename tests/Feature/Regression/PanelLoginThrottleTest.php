<?php

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Regression — the admin and portal login pages must throttle password guessing.
 *
 * WHY THIS TEST EXISTS. A 2026-07-16 audit reported "/admin and /portal logins have NO
 * throttle" as the project's sharpest live security gap, having grepped the panels'
 * route middleware stacks for `throttle:` and found nothing. That was a FALSE POSITIVE:
 * the throttle isn't route middleware, it's inside the Livewire component —
 * Filament\Auth\Pages\Login uses WithRateLimiting and calls rateLimit(5) as the first
 * statement of authenticate(). Both panels use that default page via ->login().
 *
 * Reading vendor code is not proof, and "we rely on a framework default" is exactly the
 * kind of claim that silently breaks on upgrade or the day someone subclasses Login. So
 * this pins the behaviour empirically: if a Filament upgrade or a custom login page ever
 * drops the throttle, this fails instead of a pentest finding it.
 *
 * @see docs/ROADMAP.md §6 — retired rows, do not rebuild
 */
beforeEach(function () {
    RateLimiter::clear('');
    cache()->clear();
});

it('throttles repeated failed logins on the admin panel', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $attempt = fn () => Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@mall.test', 'password' => 'wrong-password'])
        ->call('authenticate');

    // Filament allows 5 attempts per 60s, keyed by component + method + IP.
    for ($i = 0; $i < 5; $i++) {
        $attempt()->assertHasFormErrors(); // rejected on credentials, not yet throttled
    }

    // The 6th is refused before credentials are even examined — no form error, a
    // throttle notification instead.
    $attempt()->assertHasNoFormErrors()->assertNotified();
});

it('throttles repeated failed logins on the portal panel', function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $attempt = fn () => Livewire::test(Login::class)
        ->fillForm(['email' => 'tenant1@atriomwalk.test', 'password' => 'wrong-password'])
        ->call('authenticate');

    for ($i = 0; $i < 5; $i++) {
        $attempt()->assertHasFormErrors();
    }

    $attempt()->assertHasNoFormErrors()->assertNotified();
});
