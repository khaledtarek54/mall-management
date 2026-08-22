<?php

namespace App\Support\Filament;

use Illuminate\Database\Eloquent\Model;

/**
 * Ask the SCREEN's own resource whether the signed-in actor may do this to this record.
 *
 * Filament v4 routes its built-in `Create`/`Edit`/`View`/`Delete`/`ForceDelete`/`Restore` actions to
 * a Laravel POLICY. This application has none — no `app/Policies`, no `Gate::policy()`, no
 * `Gate::before()` — so `Filament\get_authorization_response()` falls through to `Response::allow()`
 * and every one of those buttons was ungated. `canCreate()` and `canEdit()` survived only because
 * the Create and Edit PAGES re-check them on mount and `abort(403)`; a `DeleteAction`, and an
 * `EditAction` opened as a MODAL from a relation manager, have no page and had no check at all.
 *
 * This resolves the resource from the Livewire component that owns the action, which is the only
 * place the answer lives — `RoleGatedActions` puts it on the resource, and a resource may
 * legitimately be more permissive than the project default (the portal lets a tenant admin delete
 * their own draft marketing post).
 *
 * **Null means "no resource to ask"**, not "allowed". A relation manager's owner resource describes
 * a different model entirely, so answering from it would answer the wrong question. Each caller
 * decides what to do with null: the delete seam falls back to the project-wide rule (super_admin,
 * and never a money record), which is fail-CLOSED; the edit seam allows, because there is no
 * equivalent floor and refusing every child-row edit in the panel would break workflows this change
 * has no business touching.
 */
final class ResourceAbility
{
    /**
     * The record-free twin: may the actor create one of these AT ALL?
     *
     * Separate because `canCreate()` takes no record, and {@see may()} keys everything on one — it
     * has to, since a relation manager's `getResource()` describes the OWNER and answering from it
     * about a child row answers the wrong question. Here that same absence is the safety: a
     * relation manager has no `getResource()`, so this returns null and the call site's own gate
     * decides, exactly as before.
     *
     * On a resource page it closes the last of the five. `canCreate()` was reachable only because
     * `CreateRecord::mount()` re-checks it and aborts 403 — so a `viewer` was offered a Create
     * button on every resource whose `canCreate()` refuses them, clicked it, and landed on an error
     * page. Not a hole (the record could never be written) but the wrong answer in the wrong place:
     * an offered button that always fails reads as a broken screen, and the four sibling actions
     * had already been taught to ask.
     */
    public static function mayCreate(mixed $livewire): ?bool
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'getResource')) {
            return null;
        }

        $resource = $livewire::getResource();

        if (! is_string($resource) || ! class_exists($resource) || ! method_exists($resource, 'canCreate')) {
            return null;
        }

        return (bool) $resource::canCreate();
    }

    /**
     * @param  'canEdit'|'canDelete'|'canForceDelete'|'canRestore'|'canView'  $ability
     */
    public static function may(string $ability, mixed $livewire, ?Model $record): ?bool
    {
        if (! $record instanceof Model || ! is_object($livewire) || ! method_exists($livewire, 'getResource')) {
            return null;
        }

        $resource = $livewire::getResource();

        if (! is_string($resource) || ! class_exists($resource)
            || ! method_exists($resource, 'getModel') || ! method_exists($resource, $ability)) {
            return null;
        }

        // The record must be the resource's OWN model. A relation manager's `getResource()` is the
        // owner's, and asking it about a child row is asking the wrong question.
        $model = $resource::getModel();

        if (! is_string($model) || ! $record instanceof $model) {
            return null;
        }

        return (bool) $resource::{$ability}($record);
    }
}
