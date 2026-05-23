<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCamExpensePool extends EditRecord
{
    protected static string $resource = CamExpensePoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
