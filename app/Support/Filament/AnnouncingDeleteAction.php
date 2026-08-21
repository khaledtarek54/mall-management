<?php

namespace App\Support\Filament;

use App\Support\DeletionPolicy;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament's `DeleteAction`, plus the {@see RecordChanged} announcement — **and the authorization
 * check the built-in delete button never had.**
 *
 * Bound in `AppServiceProvider` so `DeleteAction::make()` returns this everywhere. See
 * {@see AnnouncesRecordChange} for why the three CRUD actions need their own binding, and
 * {@see RecordChanged} for what the announcement is and who listens.
 *
 * ## Why the authorization lives here
 *
 * `RoleGatedActions::canDelete()` has said "super_admin only, and never for a money record" since
 * the deletion policy was written, and `DeletionPolicyConformanceTest` gates that no forbidden
 * Delete button or permission reappears. Neither was the gate anyone assumed: in Filament v4
 * `Resources\Pages\Page::getDefaultActionAuthorizationResponse()` routes the built-in CRUD actions
 * to a Laravel POLICY, this application has none, and `Filament\get_authorization_response()` falls
 * through to `Response::allow()`. `canCreate()`/`canEdit()` survived that only because the Create
 * and Edit pages re-check them on mount and `abort(403)`. **A `DeleteAction` has no page.**
 *
 * Proven before it was fixed: a plain `manager` deleted a holiday from its table and another user's
 * account from the user edit page, with `canDelete()` returning false for both. Roughly thirty call
 * sites carry a bare `DeleteAction::make()`, which is why this is one seam and not thirty edits —
 * the same argument that put `AuthorizedAction` in the container.
 *
 * **Both layers, deliberately.** `->authorize()` folds into `isHidden()` folds into `isDisabled()`,
 * so it removes the button and `mountAction()` refuses it; the `abort_unless` on the call path is
 * the layer that survives an upstream change to that relationship.
 */
class AnnouncingDeleteAction extends DeleteAction
{
    use AnnouncesRecordChange;

    protected function setUp(): void
    {
        parent::setUp();

        // The UI half: the button is not shown to somebody who may not press it.
        $this->authorize(fn (?Model $record): bool => DeletionPolicy::actorMayDelete($record, $this->getLivewire()));
    }

    /**
     * The gate. 403 rather than a refusal toast: reaching here means a payload was dispatched to
     * destroy a record this user may not destroy, which is not an operator mistake to explain.
     */
    protected function assertActionAuthorized(): void
    {
        abort_unless(DeletionPolicy::actorMayDelete($this->getRecord(), $this->getLivewire()), 403);
    }
}
