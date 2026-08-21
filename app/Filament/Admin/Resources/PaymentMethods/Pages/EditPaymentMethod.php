<?php

namespace App\Filament\Admin\Resources\PaymentMethods\Pages;

use App\Filament\Admin\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    // No Delete action. `#[DeletableWhenUnused]` — a rail that carried money stays in the catalogue,
    // because every document naming it reads its label. Deactivate it instead.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
