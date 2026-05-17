<?php

namespace App\Filament\Portal\Resources\Payments\Pages;

use App\Filament\Portal\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
