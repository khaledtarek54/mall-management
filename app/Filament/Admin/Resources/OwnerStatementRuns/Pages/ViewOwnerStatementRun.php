<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Pages;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * The run had only an index page, so its statements had nowhere to be shown. Read-only: every
 * transition a run has (finalise, revise, schedule, send) is an action on the list, where the
 * guards live.
 */
class ViewOwnerStatementRun extends ViewRecord
{
    protected static string $resource = OwnerStatementRunResource::class;
}
