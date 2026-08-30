<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cloudflare Turnstile on the admin sign-in form
|--------------------------------------------------------------------------
| The admin login is the one endpoint on this panel that is public by design, and behind it
| sits every tenant's lease, tax card and money history. Rate limiting slows credential
| stuffing; it does not stop it.
|
| Every refusal below is PAIRED with a control that must succeed, because a challenge that
| refuses everything satisfies the refusals on its own and reads as a pass.
*/

use App\Filament\Admin\Pages\Auth\Login;
use App\Models\User;
use App\Support\Turnstile;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->user = User::factory()->create(['password' => bcrypt('correct-horse')]);
    $this->user->assignRole('super_admin');
});

function enableTurnstile(): void
{
    config([
        'turnstile.site_key' => '0xTEST_SITE',
        'turnstile.secret_key' => '0xTEST_SECRET',
        'turnstile.verify_url' => 'https://challenges.cloudflare.test/siteverify',
    ]);
}

/* ---- off unless configured — the property the whole suite depends on ------ */

it('is inert when no keys are configured, so every other login test is unaffected', function () {
    expect(Turnstile::enabled())->toBeFalse();
    expect(Turnstile::verify(null))->toBeTrue();

    Http::fake();

    Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'correct-horse'])
        ->call('authenticate')
        ->assertHasNoErrors();

    Http::assertNothingSent();
});

/* ---- enabled: refusals, each with its control ---------------------------- */

it('refuses a sign-in carrying no challenge token, and allows one that passes', function () {
    enableTurnstile();
    Http::fake(['*' => Http::response(['success' => true])]);

    // Refusal: correct password, no token.
    Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'correct-horse'])
        ->call('authenticate')
        ->assertHasErrors('data.turnstile_token');

    expect(auth()->check())->toBeFalse();

    // Control: same credentials, a token Cloudflare accepts.
    Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'correct-horse'])
        ->set('data.turnstile_token', 'a-real-token')
        ->call('authenticate')
        ->assertHasNoErrors();
});

it('refuses a token Cloudflare rejects', function () {
    enableTurnstile();
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'correct-horse'])
        ->set('data.turnstile_token', 'forged')
        ->call('authenticate')
        ->assertHasErrors('data.turnstile_token');

    expect(auth()->check())->toBeFalse();
});

it('fails CLOSED when Turnstile cannot be reached at all', function () {
    // Failing open here would mean anyone able to block one outbound request from this box
    // can switch the protection off — which is not a protection.
    enableTurnstile();
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    expect(Turnstile::verify('a-real-token'))->toBeFalse();

    Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'correct-horse'])
        ->call('authenticate')
        ->assertHasErrors('data.turnstile_token');
});

/* ---- ordering: the challenge runs BEFORE the credential check ------------- */

it('never reaches the credential check when the challenge fails', function () {
    // Otherwise an automated client burns the 5-attempt rate limit that protects a real
    // person's account, and can lock them out without ever guessing a password.
    enableTurnstile();
    Http::fake(['*' => Http::response(['success' => false])]);

    $test = Livewire::test(Login::class)
        ->fillForm(['email' => $this->user->email, 'password' => 'WRONG-password'])
        ->set('data.turnstile_token', 'forged')
        ->call('authenticate');

    // The turnstile error, NOT "these credentials do not match our records".
    $test->assertHasErrors('data.turnstile_token');
    expect($test->errors()->get('data.email'))->toBe([]);
});

/* ---- the message is bilingual, like every other operator-facing string ---- */

it('words the refusal in both languages', function () {
    foreach (['en', 'ar'] as $locale) {
        expect(__('admin.auth.turnstile_failed', [], $locale))
            ->not->toBe('admin.auth.turnstile_failed');
    }

    expect(__('admin.auth.turnstile_failed', [], 'ar'))
        ->not->toBe(__('admin.auth.turnstile_failed', [], 'en'))
        ->toMatch('/\p{Arabic}/u');
});
