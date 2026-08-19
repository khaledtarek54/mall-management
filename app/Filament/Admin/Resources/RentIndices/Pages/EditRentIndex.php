<?php

namespace App\Filament\Admin\Resources\RentIndices\Pages;

use App\Filament\Admin\Resources\RentIndices\RentIndexResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRentIndex extends EditRecord
{
    protected static string $resource = RentIndexResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A published figure keyed by mistake is ordinary cleanup. What protects a step that has
            // already been applied is the lease's own activity trail and its rolled base value —
            // not the survival of this row, which is why the model is `#[DeletionAllowed]`.
            DeleteAction::make(),
        ];
    }
}
