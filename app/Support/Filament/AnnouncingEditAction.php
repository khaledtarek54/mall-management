<?php

namespace App\Support\Filament;

use Filament\Actions\EditAction;

/**
 * Filament's `EditAction`, plus the {@see RecordChanged} announcement.
 *
 * Bound in `AppServiceProvider` so `EditAction::make()` returns this everywhere. See
 * {@see AnnouncesRecordChange} for why the three CRUD actions need their own binding, and
 * {@see RecordChanged} for what the announcement is and who listens.
 */
class AnnouncingEditAction extends EditAction
{
    use AnnouncesRecordChange;
}
