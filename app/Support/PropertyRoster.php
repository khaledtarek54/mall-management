<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who was added to or removed from a property's ROSTER — its staff and its legal owners.
 *
 * Reported by the tester: attaching a staff member or an owner to a property left no trace in the
 * property's Activity Log. It left no trace ANYWHERE, which is the sharper version — Laravel's
 * `attach()` and `detach()` write through the query builder, so no model event fires and no
 * observer sees them, even with a pivot model bound through `->using()`. The audit trail simply had
 * a hole where the roster was.
 *
 * That matters more than the tab it was reported on. **Attaching a staff member GRANTS them access
 * to the property** — `AssignedAssets::idsFor()` reads exactly this pivot — so an unrecorded attach
 * is an unrecorded grant of access, which is the one class of change an audit trail exists for.
 *
 * The subject is the ASSET, deliberately, not the pivot row. An operator reading a property's
 * history is asking "what changed about this mall", and the roster changing is one of those things;
 * filing it against a pivot row that has no page of its own would put it nowhere anybody looks. It
 * also means these rows need no widening of the activity query to be visible.
 *
 * Values are DATA, never prose: the event is `attached`/`detached`/`updated` and the person's name
 * travels as a property, so `ActivityVocabulary` words it in the reader's language at READ time,
 * exactly as it does for every other row.
 */
class PropertyRoster
{
    public const STAFF = 'staff';

    public const OWNER = 'owner';

    /** @param  array<string, mixed>  $changed */
    public static function record(Asset $asset, string $field, User $person, string $event, array $changed = []): void
    {
        activity('asset')
            ->performedOn($asset)
            ->event($event)
            ->withProperties(array_filter([
                'attributes' => array_merge([$field => $person->name], $changed['attributes'] ?? []),
                'old' => $changed['old'] ?? null,
            ]))
            ->log($event);
    }

    /** The record a Filament attach/detach/edit hook has to hand is the related USER. */
    public static function forRecord(Asset $asset, string $field, Model $record, string $event, array $changed = []): void
    {
        if (! $record instanceof User) {
            return;
        }

        self::record($asset, $field, $record, $event, $changed);
    }
}
