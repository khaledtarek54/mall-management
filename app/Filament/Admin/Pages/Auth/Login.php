<?php

namespace App\Filament\Admin\Pages\Auth;

use App\Models\User;
use App\Support\Turnstile;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in page, with two changes.
 *
 * ## 1. A suspended account is told it is suspended
 *
 * Filament rejects a suspended user in `canAccessPanel()` and reports the generic "These
 * credentials do not match our records." Their credentials *do* match — so the person retries,
 * assumes they mistyped, resets their password, and eventually calls whoever administers the
 * system. Naming the real reason turns a support call into a two-word answer.
 *
 * This is NOT user enumeration: the message is only reachable AFTER the submitted email and
 * password have already been verified, so it tells an attacker nothing they did not just prove
 * they knew. A wrong password on a suspended account still gets the generic message, because the
 * credential check fails first.
 *
 * ## 2. Cloudflare Turnstile, when it is configured
 *
 * This form is the one endpoint on the admin panel that is public by design, and behind it sits
 * every tenant's lease, tax card and money history. `App\Support\Turnstile` explains the policy;
 * the two things that matter *here* are ordering and single use.
 *
 * **Ordering.** The challenge is checked BEFORE `parent::authenticate()`, so an automated client
 * never reaches the credential comparison at all — and never consumes the 5-attempt rate limit
 * that protects it, which would otherwise let a bot lock a real person out by exhausting it.
 *
 * **Single use.** A Turnstile token is retired the moment it is verified, so the widget has to be
 * reset after ANY failed submit — a wrong password included. Without that the person's second
 * attempt sends a token Cloudflare has already spent and they are refused twice for one mistake,
 * with the second refusal blaming the captcha. Hence `turnstile-reset` on every failure path.
 *
 * With no keys configured the field is not rendered and `verify()` returns true, so the test
 * suite and any unconfigured box behave exactly as they did before this existed.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        if (! Turnstile::enabled()) {
            return $schema;
        }

        return $schema->components([
            ...$schema->getComponents(),
            ViewField::make('turnstile_token')
                ->view('filament.forms.turnstile')
                ->hiddenLabel()
                ->dehydrated(false),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        if (Turnstile::enabled()) {
            $token = $this->form->getRawState()['turnstile_token'] ?? null;

            if (! Turnstile::verify(is_string($token) ? $token : null, request()->ip())) {
                $this->dispatch('turnstile-reset');

                throw ValidationException::withMessages([
                    'data.turnstile_token' => __('admin.auth.turnstile_failed'),
                ]);
            }
        }

        return parent::authenticate();
    }

    protected function throwFailureValidationException(): never
    {
        // The token this submit carried is already spent, whatever went wrong after it.
        $this->dispatch('turnstile-reset');

        $email = $this->form->getRawState()['email'] ?? null;
        $password = $this->form->getRawState()['password'] ?? null;

        if (filled($email) && filled($password)) {
            $user = User::where('email', $email)->first();

            if ($user?->isSuspended() && Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'data.email' => __('admin.auth.account_suspended'),
                ]);
            }
        }

        parent::throwFailureValidationException();
    }
}
