<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stephenjude\FilamentTwoFactorAuthentication\Middleware\ForceTwoFactorSetup;

/**
 * Force TOTP setup for the roles that can move money or change tenancies.
 *
 * WHY THIS CLASS EXISTS — the panel used to configure enforcement like this:
 *
 *     ->forceTwoFactorSetup(fn (): bool => auth()->user()?->hasAnyRole(...) === true)
 *
 * which enforced nothing at all, for anyone. The plugin calls `evaluate($condition)`
 * the moment the panel is REGISTERED — during boot, before any request is
 * authenticated — and stores the result as a plain bool. `auth()->user()` is null
 * there, so the closure returned false, and the panel's `array_filter` then dropped
 * the enforcement middleware entirely. Every role, including super_admin, browsed
 * the panel with no second factor, while the config, the env var and the roadmap all
 * described a mechanism that was "built".
 *
 * It is the same trap this codebase already hit and documented on `->colors()`:
 * a Filament panel builder evaluates its arguments once, at boot. A predicate that
 * depends on the CURRENT USER cannot live there — it has to live in a middleware,
 * which is per request. So the plugin is now handed a constant `true` (register the
 * middleware, always) and the role decision moved here.
 *
 * Behaviour is deliberately "force SETUP", not "block": a user in scope is redirected
 * to the TOTP setup page and can complete it themselves. Nobody is locked out of an
 * account they legitimately hold — but they cannot reach an invoice, a payment or a
 * lease until the second factor exists.
 */
class ForceTwoFactorForRoles extends ForceTwoFactorSetup
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = filament()->auth()->user();

        // Out of scope → straight through, no second factor demanded. This is the
        // check the panel builder could not do.
        if (! $this->requiresTwoFactor($user)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Whether this user must hold a second factor.
     *
     * Reads the role list from config so an operator can widen or narrow it without
     * a deploy, and so the test suite can pin the production default.
     */
    public function requiresTwoFactor(mixed $user): bool
    {
        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        $roles = (array) config('security.force_2fa_roles', []);

        return $roles !== [] && $user->hasAnyRole($roles);
    }
}
