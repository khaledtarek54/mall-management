<?php

namespace App\Support\Filament;

use Filament\Actions\RestoreAction;

/**
 * Filament's `RestoreAction`, gated on the resource's `canRestore()`.
 *
 * The weakest of the four in exposure and included anyway. Every call site today is on an Edit page,
 * whose mount already required `canEdit()` — and `canRestore()` IS `canEdit()` — so there is no
 * reachable hole. What there is, is a bare `RestoreAction::make()` waiting to be put on a table by
 * somebody, where nothing would have required anything. A seam that covers three of the four
 * built-in destructive actions is one somebody has to remember the shape of.
 */
class AnnouncingRestoreAction extends RestoreAction
{
    use AnnouncesRecordChange;

    public function isAuthorized(): bool
    {
        return $this->defaultAuthorizationAllows()
            && parent::isAuthorized()
            && (ResourceAbility::may('canRestore', $this->getLivewire(), $this->getRecord()) ?? true);
    }

    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
