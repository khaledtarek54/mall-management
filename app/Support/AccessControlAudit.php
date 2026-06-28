<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Central sink for access-control audit entries (role + permission grants /
 * revokes). Writes a single activity_log row under the 'access_control' log
 * name so the standard Activity Log viewer surfaces it (no viewer changes
 * needed beyond a label + badge colour).
 *
 * Only authenticated, human-initiated changes are recorded — seeding and CLI
 * grants (no causer) are skipped so `migrate:fresh --seed` doesn't flood the
 * trail and the test suite stays deterministic. The "who" is the whole point of
 * the audit, so a causer-less entry carries no value here.
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

        activity('access_control')
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->event('updated')
            // Shaped as {attributes: {...}} so ActivityLogChangeRenderer renders
            // it; the field name (role_granted / permission_revoked) carries the
            // grant-vs-revoke semantics, the value lists the names.
            ->withProperties(['attributes' => [$action => implode(', ', $names)]])
            ->log($action);
    }

    /**
     * Normalise a spatie permission-event payload to an array of names. The
     * payload type is INCONSISTENT across the events: role/permission *attach*
     * and role *detach* carry an array of primary keys, while permission
     * *detach* carries an Eloquent model (or a collection of them). Resolve
     * every shape here so listeners don't have to.
     *
     * @param  class-string<Model>  $modelClass  Role::class or Permission::class
     * @return array<int, string>
     */
    public static function namesFrom(mixed $payload, string $modelClass): array
    {
        $items = $payload instanceof Collection
            ? $payload->all()
            : (is_array($payload) ? $payload : [$payload]);

        return collect($items)
            ->map(function ($item) use ($modelClass) {
                if ($item instanceof Model) {
                    return $item->name ?? '#'.$item->getKey();
                }
                if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                    return optional($modelClass::find($item))->name ?? '#'.$item;
                }

                return is_string($item) ? $item : null; // already a name
            })
            ->filter()
            ->values()
            ->all();
    }
}
