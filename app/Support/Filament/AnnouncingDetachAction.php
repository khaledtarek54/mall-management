<?php

namespace App\Support\Filament;

use Filament\Actions\DetachAction;

/**
 * Filament's `DetachAction`, with the seam the five CRUD actions already have.
 *
 * The twin of {@see AnnouncingAttachAction}, and the more consequential half: detaching REVOKES
 * access — a user's assignment to a property, a member's place in a department — so it is the
 * direction where losing Filament's read-only-on-a-View-page deny actually costs something.
 *
 * See {@see AnnouncingAttachAction} for why only five of the sixteen read-only-sensitive action
 * types were bound, and {@see AnnouncesRecordChange::defaultAuthorizationAllows()} for what the
 * seam does.
 */
class AnnouncingDetachAction extends DetachAction
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
