<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Pages;

use App\Filament\Admin\Actions\OwnerStatementRunActions;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\ViewRecord;

/**
 * The run had only an index page, so its statements had nowhere to be shown. Read-only: every
 * transition a run has (finalise, revise, schedule, send) is an action on the list, where the
 * guards live.
 */
class ViewOwnerStatementRun extends ViewRecord
{
    use RefreshesRecordState;

    protected static string $resource = OwnerStatementRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...OwnerStatementRunActions::all(),
        ];
    }
}
