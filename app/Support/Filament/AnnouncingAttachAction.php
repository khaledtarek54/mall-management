<?php

namespace App\Support\Filament;

use Filament\Actions\AttachAction;

/**
 * Filament's `AttachAction`, with the seam the five CRUD actions already have.
 *
 * `RelationManager::getDefaultActionAuthorizationResponse()` applies its
 * `isReadOnly() ? Response::deny() : …` clause to SIXTEEN action types, not five — Associate,
 * Attach, Detach, Dissociate, Import, Replicate and the bulk actions among them. Only the five CRUD
 * ones were bound to an `Announcing*` subclass, and `Action::make()` resolves `app(static::class)`,
 * so every other type resolves itself and never sees the binding. A `->authorize()` on one of those
 * therefore still REPLACES the deny instead of narrowing it — the exact defect
 * {@see AnnouncesRecordChange::defaultAuthorizationAllows()} exists to close, surviving in the
 * action types nobody looked at. Fixed five, left eleven.
 *
 * Attach and Detach are the two this codebase actually uses (three relation managers each), and
 * both grant or revoke ACCESS: `AssetStaffRelationManager` attaches a user to a property,
 * `DepartmentMembersRelationManager` to a department. Bound for that reason rather than for
 * completeness — the day someone adds a `ViewAsset` page, which is the same UX move that produced
 * `ViewTenant`, those relation managers become read-only and these must refuse with the rest.
 *
 * `RelationManagerActionsSeeTheReadOnlyDenyTest` derives the list from the vendor method itself, so
 * a type this app starts using later cannot slip through the way these two did.
 */
class AnnouncingAttachAction extends AttachAction
{
    use AnnouncesRecordChange;

    public function isAuthorized(): bool
    {
        return $this->defaultAuthorizationAllows() && parent::isAuthorized();
    }

    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
