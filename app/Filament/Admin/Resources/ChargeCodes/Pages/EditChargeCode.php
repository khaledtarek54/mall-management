<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Pages;

use App\Enums\InvoiceItemType;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Models\ChargeCode;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChargeCode extends EditRecord
{
    protected static string $resource = ChargeCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A code the billing engine references by name cannot be deleted — CAM recovery and
            // percentage rent drive the anti-double-bill probe, late fees and NSF fees drive the
            // settlement order. Removing the row would not remove the behaviour; it would only
            // leave the engine posting a code the catalogue no longer describes.
            DeleteAction::make()
                ->visible(fn (ChargeCode $record) => ! in_array($record->code, InvoiceItemType::values(), true)),
        ];
    }
}
