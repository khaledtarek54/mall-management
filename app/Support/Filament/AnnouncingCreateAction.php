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
}
