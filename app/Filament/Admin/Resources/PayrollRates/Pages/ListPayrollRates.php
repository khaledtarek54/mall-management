<?php

namespace App\Filament\Admin\Resources\PayrollRates\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\PayrollRates\PayrollRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRates extends ListRecords
{
    protected static string $resource = PayrollRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
