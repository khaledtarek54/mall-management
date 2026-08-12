<?php

namespace App\Filament\Portal\Resources\Payments\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()), ];
    }
}
