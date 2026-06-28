<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Central sink for access-control audit entries (role + permission grants /
 * revokes, role deletion). Writes a single activity_log row under the
 * 'access_control' log name so the standard Activity Log viewer surfaces it.
 *
 * Auditing is done with explicit before/after DIFFS at each UI mutation point
 * (the User pages, the Department membership actions, the Role pages) rather
 * than via spatie's permission events, because:
 *   - Filament's roles Select saves through the raw belongsToMany pivot
 *     (sync()/detach()), which fires NO spatie event — so the primary admin
 *     path would be invisible to an event listener.
 *   - spatie fires its events with the FULL requested set, not the delta, so an
 *     event listener logs phantom grants on every idempotent re-assign.
 * A diff is delta-aware by construction (no phantoms) and catches every path.
 *
 * Only authenticated, human-initiated changes are recorded ({@see log()} gates
 * on auth()->check()): seeding and CLI grants have no causer, so they're
 * skipped — the "who" is the whole point of the trail.
 *
 * Deliberate non-audit: deleting a User cascades its model_has_roles pivot away,
 * which we do NOT log — removing an account is not a privilege change to a
 * surviving subject. Deleting a Role IS audited (EditRole's DeleteAction->before
 * logs role_deleted, a mass revoke). Bulk role/user delete stays off project-wide
 * (guarded by BulkDeleteDisabledTest), so there is no unaudited bulk-revoke path.
 */
class AccessControlAudit
{
    /**
     * @param  array<int, string>  $names  resolved role/permission names
     */
    public static function log(Model $subject, string $action, array $names): void
    {
        $names = array_values(array_filter($names));

        if ($names === [] || ! auth()->check()) {
            return;
        }

        try {
            activity('access_control')
                ->performedOn($subject)
                ->causedBy(auth()->user())
                ->event('updated')
                // withChanges() writes the `attribute_changes` column — the one
                // ActivityLogChangeRenderer (and the Activity Log UI) reads. Using
                // withProperties() here would store the data in the separate
                // `properties` column and the UI would render it as "—".
                // Shaped as {attributes: {...}} so the renderer shows each field;
                // the field name (role_granted / permission_revoked) carries the
                // grant-vs-revoke semantics, the value lists the names.
                ->withChanges(['attributes' => [$action => implode(', ', $names)]])
                ->log($action);
        } catch (\Throwable $e) {
            // Auditing is advisory — a logging failure must NEVER abort or 500 the
            // privileged operation it records (the grant is already committed).
            report($e);
        }
    }

    /**
     * Log the role delta on a user (subject) — only genuine adds/removes produce
     * a row, so an unchanged re-save is silent.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function logRoleDiff(Model $user, array $before, array $after): void
    {
        self::log($user, 'role_granted', array_values(array_diff($after, $before)));
        self::log($user, 'role_revoked', array_values(array_diff($before, $after)));
    }

    /**
     * Log the permission delta on a role (subject).
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function logPermissionDiff(Model $role, array $before, array $after): void
    {
        self::log($role, 'permission_granted', array_values(array_diff($after, $before)));
        self::log($role, 'permission_revoked', array_values(array_diff($before, $after)));
    }
}
