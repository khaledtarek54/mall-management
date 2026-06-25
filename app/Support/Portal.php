<?php

namespace App\Support;

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
        return Auth::guard('portal')->user();
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
}
