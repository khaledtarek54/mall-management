<?php

namespace App\Support\Filament;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * A party is attached to a property ONCE — and the picker not offering them is not what enforces it.
 *
 * `asset_owner` and `asset_user` both carry `unique(user_id, asset_id)`, so a second attach of the
 * same person is a duplicate-key `QueryException` — an uncaught 500 rendered as Filament's
 * "Error while loading page" toast, which tells the operator nothing and looks like the panel
 * breaking rather than like a rule. That is what the tester hit attaching a second owner: the only
 * user holding the `owner` role was the one already attached, so the picker offered them again.
 *
 * Filament's own `AttachAction::getRecordSelect()` excludes already-attached records
 * (`whereDoesntHave($table->getInverseRelationship(), ...)`) and BOTH call sites had replaced that
 * builder with a bare `->options(User::query()...)` to narrow by role — which silently took the
 * exclusion with it. The same shape CLAUDE.md records for `EntitySelect` and `->relationship()`:
 * overriding an upstream callback reverts a guard while the call site still looks correct. They
 * narrow through `->recordSelectOptionsQuery()` now, which composes with the exclusion instead of
 * replacing it.
 *
 * **This class is the layer that is actually a gate.** A narrowed option list is not one — the
 * chosen id arrives in the Livewire payload and nothing upstream re-checks it — so the refusal is
 * asked again at the write, in the operator's own language, and it reads as a rule rather than as a
 * crash. Same reasoning that keeps `assertAssetInScope()` behind every scoped picker.
 */
class AttachedOnce
{
    /**
     * @param  Model  $owner  the record being attached TO (the property)
     * @param  string  $relation  the BelongsToMany relation on that record
     * @param  mixed  $recordId  what the modal submitted
     * @param  string  $refusalKey  an `admin.refusals.*` key taking a `:name` placeholder
     */
    public static function assert(Model $owner, string $relation, mixed $recordId, string $refusalKey): void
    {
        if (blank($recordId)) {
            return; // `required` on the select owns that refusal, and says it better.
        }

        $existing = $owner->{$relation}()
            ->wherePivot($owner->{$relation}()->getRelatedPivotKeyName(), $recordId)
            ->first();

        if (! $existing) {
            return;
        }

        throw new DomainException(__($refusalKey, [
            // The person's own name, never the id — the operator picked a name and has to be told
            // which one they picked twice.
            'name' => $existing->name ?? (string) $recordId,
        ]));
    }
}
