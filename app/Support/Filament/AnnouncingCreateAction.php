<?php

namespace App\Support\Filament;

use Filament\Actions\CreateAction;

/**
 * Filament's `CreateAction`, plus the {@see RecordChanged} announcement.
 *
 * Bound in `AppServiceProvider` so `CreateAction::make()` returns this everywhere. See
 * {@see AnnouncesRecordChange} for why the three CRUD actions need their own binding, and
 * {@see RecordChanged} for what the announcement is and who listens.
 */
class AnnouncingCreateAction extends CreateAction
{
    use AnnouncesRecordChange;

    /**
     * The call site's answer AND Filament's default AND the resource's own `canCreate()` — see
     * {@see AnnouncesRecordChange::defaultAuthorizationAllows()} and
     * {@see ResourceAbility::mayCreate()}. A relation-manager `CreateAction` that declares
     * `->authorize()` would otherwise discard Filament's read-only-on-a-View-page deny, and this
     * was the one of the five siblings that never asked the resource anything — so a role its
     * `canCreate()` refuses was still offered the button, and found out by landing on the 403 that
     * `CreateRecord::mount()` throws.
     */
    public function isAuthorized(): bool
    {
        return $this->defaultAuthorizationAllows()
            && parent::isAuthorized()
            && (ResourceAbility::mayCreate($this->getLivewire()) ?? true);
    }

    protected function assertActionAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
    }
}
