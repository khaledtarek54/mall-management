<?php

namespace App\Filament\Admin\Resources\Disbursements\Pages;

use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use Filament\Resources\Pages\ListRecords;

class ListDisbursements extends ListRecords
{
    protected static string $resource = DisbursementResource::class;

    // Disbursements are scheduled from a finalised owner statement (the run's "Schedule payout"
    // action), never created blank here — so no create header action.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
