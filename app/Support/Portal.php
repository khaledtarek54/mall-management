<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Auth;

/**
 * Portal session context. The portal guard authenticates a TenantUser (req #9);
 * these helpers resolve the company record + the tenant_id that all portal
 * queries scope to, and whether the current user may write.
 */
class Portal
{
    public static function user(): ?TenantUser
    {
        // Both tenant-facing surfaces authenticate the same TenantUser row (2026-09-05): the portal
        // through a session guard, the mobile app through a Sanctum token. Asking only the portal
        // guard answered NULL on every API request, so the two controllers that record who acted on
        // a request stored nobody whenever the act came from the phone.
        //
        // The `instanceof` is load-bearing, not defensive typing: Sanctum's guard FALLS BACK to
        // `config('sanctum.guard')` — `web` by default — when the request carries no token, so on
        // any admin-panel request this line hands back the signed-in `User`. That is a TypeError on
        // the way out and, worse, a `User` reaching `->tenant`/`->is_admin` if the return type ever
        // loosened. The portal context is a TenantUser or it is nobody.
        $user = Auth::guard('portal')->user() ?? Auth::guard('tenant-api')->user();

        return $user instanceof TenantUser ? $user : null;
    }

    /** The company (Tenant) the current portal user belongs to. */
    public static function tenant(): ?Tenant
    {
        return static::user()?->tenant;
    }

    /** The tenant_id every portal query scopes to. */
    public static function tenantId(): ?int
    {
        return static::user()?->tenant_id;
    }

    /** Only admin tenant users may submit/write; others are read-only. */
    public static function isAdmin(): bool
    {
        return (bool) static::user()?->is_admin;
    }

    /**
     * Clamp a client-supplied lease id to the signed-in tenant's own leases.
     * Returns null when blank or when the lease belongs to someone else.
     *
     * **Use this for any query keyed on a portal form's `lease_id`.** A Select's
     * `->options()` scope the *rendering*, not the payload — Livewire state is
     * attacker-controlled, and Laravel runs every field rule in ONE pass, so an
     * `in`-rule refusal does not stop a `Rule::unique` (a raw query no scope touches)
     * from having already run. Keyed raw, such a rule answers "has <lease> declared
     * sales for <month>?" for *another retailer's* lease — a cross-tenant existence
     * oracle over a competitor's leases and their reporting calendar. Returning null
     * collapses it: the rule matches nothing.
     *
     * The portal's analogue of `TenantScope::clampAssetId()`; the scope here is the
     * tenant, not the property. Guarded by `UniqueRuleScopeConformanceTest`.
     */
    public static function clampLeaseId(mixed $leaseId): ?int
    {
        if (blank($leaseId) || static::tenantId() === null) {
            return null;
        }

        $ownsIt = Lease::whereKey($leaseId)
            ->where('tenant_id', static::tenantId())
            ->exists();

        return $ownsIt ? (int) $leaseId : null;
    }
}
