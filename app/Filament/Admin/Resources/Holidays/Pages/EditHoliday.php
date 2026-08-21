<?php

namespace App\Filament\Admin\Resources\Holidays\Pages;

use App\Filament\Admin\Resources\Holidays\HolidayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHoliday extends EditRecord
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Filament stamps `asset_id` on create but never on update, so the edit path guards too — and
     * it guards BOTH ends of the move.
     *
     * The original value is passed because taking a date AWAY from a mall is as much a write to
     * that mall as adding one. A restricted admin who can see the national rows (the list shows them
     * deliberately) could otherwise re-home one onto their own property and delete it everywhere
     * else, passing a guard that only ever looked at what they submitted.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        HolidayResource::assertMayWrite(
            isset($data['asset_id']) ? (int) $data['asset_id'] : null,
            $this->record->getOriginal('asset_id') === null ? null : (int) $this->record->getOriginal('asset_id'),
        );

        return $data;
    }
}
