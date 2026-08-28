<?php

namespace App\Listeners;

use App\Models\VendorContact;
use Illuminate\Auth\Events\Login;

/**
 * Record when a contractor last signed in.
 *
 * **Why the column is not decoration.** §9 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md` names the
 * one thing that would make the vendor portal a bad idea: *contractors who will not log in*. If that
 * happens the portal makes the response SLA **worse**, because `acknowledged_at` stops being filled
 * by staff and starts being filled by nobody. `last_login_at` is how an operator finds that out
 * before the SLA figures quietly become fiction — so a column nothing wrote would have left the
 * design's own stated risk unmeasurable.
 *
 * Caught in review rather than by a gate: the column was added, cast, and written by nothing — the
 * same inert-mechanism shape as `tenants.locale`, which was fillable on nothing and answered null
 * for every tenant for two weeks.
 *
 * `saveQuietly()`: signing in is not an edit of the contact, and an audit row per login would bury
 * the changes a person actually made. Wired by Laravel's listener auto-discovery.
 */
class StampVendorLastLogin
{
    public function handle(Login $event): void
    {
        // Guard-scoped rather than model-scoped: `TenantUser` and `User` have no such column, and a
        // listener that assumed every authenticatable had one would fatal on the admin login.
        if ($event->guard !== 'vendor' || ! $event->user instanceof VendorContact) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
