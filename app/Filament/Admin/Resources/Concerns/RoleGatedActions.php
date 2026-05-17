<?php

namespace App\Filament\Admin\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Role-based gating for Filament Resource CRUD actions.
 *
 * - super_admin → everything
 * - manager     → create + edit, no delete
 * - viewer      → read-only
 */
trait RoleGatedActions
{
    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canForceDeleteAny(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false;
    }

    public static function canRestoreAny(): bool
    {
        return Auth::user()?->hasAnyRole(['super_admin', 'manager']) ?? false;
    }
}
