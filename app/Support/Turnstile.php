<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile for the admin sign-in form.
 *
 * ## Why a challenge on this form specifically
 *
 * The admin panel holds every tenant's lease, tax card and money history, and its sign-in form
 * is the one endpoint that is public by design. Rate limiting slows credential stuffing; it does
 * not stop it. On STAGING this sits behind Cloudflare Access as well, which is belt and braces —
 * but production's panel will not be behind Access, and this is the layer that survives that move.
 *
 * ## Off unless configured, and that is load-bearing
 *
 * `enabled()` requires BOTH keys. With neither set the challenge does not render and
 * `verify()` is never consulted, so the test suite — which submits this form in every role-matrix
 * shard — and every unconfigured box behave exactly as before. It is also the escape hatch: clear
 * the keys and re-cache the config and the panel is loginable again without a deploy, which
 * matters because of the next paragraph.
 *
 * ## It fails CLOSED, deliberately
 *
 * A missing, reused or rejected token refuses the sign-in. So does a Turnstile that cannot be
 * reached at all. Failing open on a transport error would mean anyone able to block one outbound
 * request from this box can switch the protection off, which is not a protection. The cost is that
 * a Cloudflare outage makes the panel un-loginable, which is why the escape hatch above exists and
 * why the transport failure is reported through `OpsLog` rather than swallowed — the operator has
 * to be able to tell "my password is wrong" from "the challenge cannot be checked".
 *
 * A token is single-use and short-lived at Cloudflare's end; we do not cache or replay it.
 */
final class Turnstile
{
    public static function enabled(): bool
    {
        return filled(config('turnstile.site_key')) && filled(config('turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return config('turnstile.site_key');
    }

    /**
     * True only when Cloudflare affirmatively says the token is good.
     *
     * Every other outcome — blank token, HTTP failure, malformed body, `success: false` —
     * is a refusal. See the class docblock for why an unreachable Turnstile is a refusal too.
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('turnstile.timeout', 5))
                ->post((string) config('turnstile.verify_url'), array_filter([
                    'secret' => config('turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            OpsLog::error('turnstile.unreachable', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            OpsLog::error('turnstile.unreachable', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return $response->json('success') === true;
    }
}
