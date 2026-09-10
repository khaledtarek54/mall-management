<?php

namespace App\Support\Filament;

use App\Support\DeletionPolicy;
use Filament\Actions\DeleteAction;
use Illuminate\Contracts\Support\Htmlable;
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

    /**
     * The call site's answer AND the seam's — never one instead of the other.
     *
     * `->authorize()` writes a SINGLE SLOT (`CanBeAuthorized::$authorization`), so setting it in
     * `setUp()` meant any call site that declared its own authorization silently replaced the
     * seam's. Eight relation managers do exactly that, and the result was worse than the hole it
     * replaced: the call site won the UI, the hard `abort_unless` still asked the policy, and an
     * accounting user was shown an ENABLED Delete button that answered with a 403 error page
     * mid-workflow. Two layers that disagree are not defence in depth.
     *
     * An AND means a call site can only ever NARROW the seam. It cannot opt out of it by accident,
     * which is the entire argument for putting the check in the container.
     */
    public function isAuthorized(): bool
    {
        return $this->defaultAuthorizationAllows()
            && parent::isAuthorized()
            && DeletionPolicy::actorMayDelete($this->getRecord(), $this->getLivewire());
    }

    /**
     * A record that CANNOT be deleted does not offer a Delete button that looks like it will work.
     *
     * Reported by the tester on a lease: press Delete, confirm on a modal that asks nothing but
     * "are you sure", watch the page reload, and find the lease still there with **no message of any
     * kind**. `RefusesDeletionWhenReferenced` was working perfectly — the lease had an open invoice,
     * the refusal fired, nothing was deleted — and the operator was told none of it. A destructive
     * control that silently does nothing is read as a broken button, which is how it was filed.
     *
     * **Disabled, not hidden, and not an authorization failure.** Blockers are a RULE the operator
     * ran into, not a right they lack: folding this into {@see isAuthorized()} would remove the
     * button and answer 403, which is the same silence in a different costume. Disabled keeps the
     * affordance on screen and lets the tooltip say WHY and what to do instead — the same
     * "a guarded control must LOOK guarded" idiom the money forms follow. Yardi refuses rather than
     * warns here too, and shows the reason.
     *
     * Costs one record's worth of COUNTs, memoised on the model. All six `DeletableWhenUnused`
     * models compose Delete on the RECORD PAGE and none on a table row, so this is never the
     * per-row N+1 it would be on a list — checked before it was written, not assumed.
     */
    public function isDisabled(): bool
    {
        return parent::isDisabled() || $this->deletionBlockers() !== [];
    }

    public function getTooltip(): ?string
    {
        $blockers = $this->deletionBlockers();

        return $blockers === []
            ? parent::getTooltip()
            : __('admin.errors.record_still_referenced', [
                'record' => $this->recordLabel(),
                'blockers' => implode(', ', $blockers),
                'instead' => DeletionPolicy::insteadFor($this->getRecord()::class) ?? __('admin.errors.deactivate_instead'),
            ]);
    }

    /**
     * The same sentence in the modal, for the case the tooltip cannot reach — a touch device has no
     * hover, which is the standing reason this project does not let a tooltip carry a constraint on
     * its own.
     */
    public function getModalDescription(): string|Htmlable|null
    {
        return $this->deletionBlockers() === []
            ? parent::getModalDescription()
            : $this->getTooltip();
    }

    /**
     * What currently points at this record, or `[]` for a model that does not classify deletion.
     *
     * @return array<int, string>
     */
    private function deletionBlockers(): array
    {
        $record = $this->getRecord();

        return $record instanceof Model && method_exists($record, 'deletionBlockers')
            ? $record->deletionBlockers()
            : [];
    }

    private function recordLabel(): string
    {
        $record = $this->getRecord();

        return $record instanceof Model ? class_basename($record) : '';
    }

    /**
     * The gate. 403 rather than a refusal toast: reaching here means a payload was dispatched to
     * destroy a record this user may not destroy, which is not an operator mistake to explain.
     *
     * Asks {@see isAuthorized()} — the same question the button asked — so the two layers cannot
     * answer differently.
     */
    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
