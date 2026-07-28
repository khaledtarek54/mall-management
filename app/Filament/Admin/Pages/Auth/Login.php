<?php

namespace App\Filament\Admin\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in page, with one change: a suspended account is told it is suspended.
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
 */
class Login extends BaseLogin
{
    protected function throwFailureValidationException(): never
    {
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
