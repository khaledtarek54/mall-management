<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Who may read the queue dashboard.
 *
 * `/horizon` IS A SCREEN, AND IT IS THE ONE SCREEN NO CONFORMANCE GATE IN THIS
 * PROJECT CAN SEE. It is not a Filament resource or page, so it is invisible to
 * `RoleScreenMatrixShard{1..5}Test`, to `EveryRoleMeetsEveryScreenTest`, to
 * `ScreenGuides` and to the `Navigation` registry — every mechanism that would
 * otherwise force a decision about who may open it. The decision has to be made
 * here, in writing, or it is made by whatever a package happens to default to.
 *
 * What it shows is not infrastructure trivia: a job payload is the serialised
 * job, so the pending and failed lists name tenants, invoice ids and amounts —
 * the same material `MALL_ADMIN_WITHHELD` and the activity-feed scope rule exist
 * to keep inside the full-portfolio roles. It also carries VERBS (retry a failed
 * job, pause the queue), and retrying a money job is not a read.
 *
 * So: `super_admin` only. This deliberately does NOT go through a
 * `{module}.view` permission — a grantable right would put "who may replay a
 * posting job" wherever the roles matrix happens to stand this week, which is
 * the same reasoning that keeps the module switches and `DeletionPolicy` off the
 * permission catalogue.
 *
 * `$user instanceof User` is FIRST and is load-bearing, not defensive style.
 * This app has four auth surfaces; `TenantUser` and `VendorContact` do not use
 * spatie's `HasRoles`, so calling `hasRole()` on one is a fatal, not a `false`.
 * They authenticate on their own guards, so `$request->user()` on the default
 * `web` guard is already null for them — but the instance check is what makes
 * that a refusal rather than a 500 if a guard is ever re-pointed.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon's own `authorization()` reads
     * `Gate::check('viewHorizon', ...) || app()->environment('local')`.
     *
     * The `local` escape is dropped. `App\Support\Deployment` is the ONE reading
     * of which tier a box is, and its rule is that an unanticipated environment
     * inherits the STRICTER treatment — an `|| environment(...)` bypass sitting
     * inside a vendor base class is exactly the second reading that rule exists
     * to prevent. It also makes the refusal testable: with the escape in place a
     * test proving an ordinary role is refused would pass or fail depending on
     * the `APP_ENV` of whoever ran it.
     *
     * Signing in at `/admin` as super_admin is the same session, so nothing is
     * lost on a workstation.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Refuses an unauthenticated visitor without being asked to: a gate whose
     * callback types its first parameter non-nullable is never invoked for a
     * guest, and `Gate::check()` answers false.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user): bool => $user instanceof User && $user->hasRole('super_admin'));
    }
}
