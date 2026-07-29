<?php

/**
 * A refusal the operator caused must not look like a crash.
 *
 * `DomainException` is how this app says "that is not allowed, here is why" —
 * the accounting period is closed, the credit would be over-applied, the stock
 * is not there. Services have always thrown it, and the Filament pages that call
 * those services catch it one by one and turn it into a notification.
 *
 * That stopped scaling when the posting-date guards moved onto MODELS
 * (App\Models\Concerns\GuardsPostingDate covers Invoice, Payment, Expense,
 * MarketingSpend, DepositTransaction and FixedAsset at once). The refusal can now
 * come from any create page, any edit page, any relation manager, any import —
 * and uncaught it rendered the 500 page, losing the form the operator had just
 * filled in and reading as "the app broke" rather than "the period is closed".
 *
 * Handled centrally instead, because the page that gets forgotten is the page the
 * operator finds.
 */

use Illuminate\Support\Facades\Route;

it('sends the operator back with the reason, not to the error page', function () {
    Route::middleware('web')->get('/__test_domain_refusal', function () {
        throw new DomainException('Accounting period 2026-07 is closed.');
    });

    $this->get('/__test_domain_refusal')
        ->assertStatus(302)   // back to where they were, form intact
        ->assertSessionHas('filament.notifications');
});

it('carries the exception message, not a generic apology', function () {
    // "Something went wrong" would be useless here — the whole value is that the
    // message says WHICH period is closed and what to do about it.
    Route::middleware('web')->get('/__test_domain_refusal_msg', function () {
        throw new DomainException('Accounting period 2026-07 is closed. Reopen it, or use an open date.');
    });

    $this->get('/__test_domain_refusal_msg');

    $notifications = json_encode(session('filament.notifications'));

    expect($notifications)->toContain('Accounting period 2026-07 is closed');
});

it('leaves the mobile API contract alone', function () {
    // /api/* has its own error shape ({message, statusCode}) that the Flutter client
    // parses. A redirect there would be nonsense.
    Route::middleware('api')->get('/api/__test_domain_refusal', function () {
        throw new DomainException('nope');
    });

    $this->getJson('/api/__test_domain_refusal')
        ->assertJsonStructure(['message', 'statusCode']);
});

it('does not swallow real faults', function () {
    // Only DomainException means "you did something not allowed". A TypeError is a bug
    // and must keep going to the error page (and to Sentry) rather than being dressed
    // up as an operator mistake.
    Route::middleware('web')->get('/__test_real_fault', function () {
        throw new RuntimeException('a genuine bug');
    });

    $this->withExceptionHandling()
        ->get('/__test_real_fault')
        ->assertStatus(500);
});
