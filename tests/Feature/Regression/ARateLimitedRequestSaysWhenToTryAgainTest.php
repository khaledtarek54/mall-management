<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

/**
 * Regression — mobile §L L5. A 429 says when to try again, in the reader's language.
 *
 * The API's catch-all renderer rebuilt every HTTP error as a fresh `{message, statusCode}` response and
 * dropped the exception's own headers — so the one instruction `MOBILE-API.md` gave the app about a
 * 429, "respect `Retry-After`", named a header that was never sent. And its message was the framework's
 * English literal, "Too Many Attempts.", under Arabic too.
 *
 * The Arabic half hides a trap worth a test of its own: the throttle runs FIRST, ahead of
 * `SetApiLocale`, so the app locale is still the default when a 429 is rendered and a plain `__()`
 * answers English whatever the header says — green in every English-only test. The renderer reads the
 * locale off the request, by the method the middleware uses. The control is a 404, which must keep its
 * own words: the message is replaced for the THROTTLE's exception, not for everything that fails.
 */
function spendTheSignInCounter(array $headers = []): TestResponse
{
    $wrong = ['email' => 'nobody@atriomwalk.test', 'password' => 'wrong', 'device_name' => 'probe'];

    for ($i = 0; $i < 5; $i++) {
        test()->postJson('/api/v1/auth/login', $wrong)->assertUnauthorized();
    }

    return test()->postJson('/api/v1/auth/login', $wrong, $headers)->assertStatus(429);
}

it('tells the app when to try again — Retry-After travels with the 429', function () {
    $response = spendTheSignInCounter();

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0, 'a 429 with no Retry-After gives the app nothing to wait for')
        ->and($response->json('statusCode'))->toBe(429);
});

it('says it in English to an English reader, in words and not the framework literal', function () {
    spendTheSignInCounter(['Accept-Language' => 'en'])
        ->assertJsonPath('message', __('api.too_many_requests', [], 'en'));
});

it('says it in Arabic to an Arabic reader — the throttle runs before the locale is set', function () {
    // `Lang::has()` falls back to English unless told not to, and a missing Arabic key would then
    // compare the English sentence against itself and pass.
    expect(Lang::has('api.too_many_requests', 'ar', false))->toBeTrue('the Arabic catalogue has no too_many_requests');

    $message = spendTheSignInCounter(['Accept-Language' => 'ar'])->json('message');

    expect($message)->toBe(__('api.too_many_requests', [], 'ar'))
        ->and($message)->not->toBe(__('api.too_many_requests', [], 'en'));
});

it('leaves every other error its own words — a 404 is not told to slow down', function () {
    $tenant = makeTenant();

    $message = $this->getJson('/api/v1/me/invoices/999999', apiHeaders($tenant))->assertNotFound()->json('message');

    expect($message)->not->toBe(__('api.too_many_requests', [], 'en'));
});
