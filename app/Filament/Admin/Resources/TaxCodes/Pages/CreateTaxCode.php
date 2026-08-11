<?php

namespace App\Filament\Admin\Resources\TaxCodes\Pages;

use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxCode extends CreateRecord
{
    protected static string $resource = TaxCodeResource::class;

    /**
     * Straight to the rate ladder, because a code without one cannot be switched on.
     *
     * The alternative — back to the list — leaves the operator looking at a new row marked
     * inactive with nothing telling them what is missing.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
