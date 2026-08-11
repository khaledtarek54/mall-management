<?php

namespace App\Filament\Admin\Resources\TaxCodes\Pages;

use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Models\TaxCode;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxCode extends EditRecord
{
    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Refused while any charge code is billed under it — see App\Support\DeletionPolicy.
            // The button stays visible so the refusal explains itself rather than the option
            // simply not being there.
            DeleteAction::make()
                ->visible(fn (TaxCode $record) => $record->chargeCodes()->doesntExist()),
        ];
    }
}
