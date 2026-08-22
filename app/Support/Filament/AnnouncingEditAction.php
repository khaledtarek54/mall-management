<?php

namespace App\Support\Filament;

use Filament\Actions\EditAction;

/**
 * Filament's `EditAction`, plus the {@see RecordChanged} announcement.
 *
 * Bound in `AppServiceProvider` so `EditAction::make()` returns this everywhere. See
 * {@see AnnouncesRecordChange} for why the three CRUD actions need their own binding, and
 * {@see RecordChanged} for what the announcement is and who listens.
 *
 * ## …and the authorization the button did not have
 *
 * Filament v4 routes the built-in CRUD actions to a Laravel policy, and this application has none —
 * see {@see ResourceAbility}. An `EditAction` that NAVIGATES to the edit page was covered by
 * accident, because `EditRecord::authorizeAccess()` re-checks `canEdit()` on mount; one opened as a
 * MODAL writes without ever touching that page. Either way a `viewer` was shown the button: 38
 * tables gated `EditAction` on `canEdit()` by hand and 19 did not, which is the shape that argues
 * for a seam rather than a 20th edit.
 *
 * {@see ResourceAbility::may()} returns null when there is no resource to ask — a relation manager's
 * child row — and that ALLOWS here, deliberately. There is no project-wide floor for "may this be
 * edited" the way there is for deletion, and refusing every child-row edit in the panel would break
 * workflows this change has no business touching.
 */
class AnnouncingEditAction extends EditAction
{
    use AnnouncesRecordChange;

    /**
     * The call site's answer AND the resource's — see {@see AnnouncingDeleteAction::isAuthorized()}
     * for why this is an AND on `isAuthorized()` rather than an `->authorize()` in `setUp()`.
     */
    public function isAuthorized(): bool
    {
        return $this->defaultAuthorizationAllows()
            && parent::isAuthorized()
            && (ResourceAbility::may('canEdit', $this->getLivewire(), $this->getRecord()) ?? true);
    }

    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
