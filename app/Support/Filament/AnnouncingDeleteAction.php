<?php

namespace App\Support\Filament;

use Filament\Actions\DeleteAction;

/**
 * Filament's `DeleteAction`, plus the {@see RecordChanged} announcement.
 *
 * Bound in `AppServiceProvider` so `DeleteAction::make()` returns this everywhere. See
 * {@see AnnouncesRecordChange} for why the three CRUD actions need their own binding, and
 * {@see RecordChanged} for what the announcement is and who listens.
 */
class AnnouncingDeleteAction extends DeleteAction
{
    use AnnouncesRecordChange;
}
