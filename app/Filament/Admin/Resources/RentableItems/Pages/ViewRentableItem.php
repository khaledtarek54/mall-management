<?php

namespace App\Filament\Admin\Resources\RentableItems\Pages;

use App\Filament\Admin\Resources\RentableItems\RentableItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only view — the gate insists every table with a form has one, and it is right to.
 *
 * A viewer or an owner should be able to answer "who has bay 42, and what do they pay" without
 * being handed an edit form they are not allowed to submit.
 */
class ViewRentableItem extends ViewRecord
{
    protected static string $resource = RentableItemResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
