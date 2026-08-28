<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Support\Filament\AnnouncesLedgerRestatement;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustody extends EditRecord
{
    use AnnouncesLedgerRestatement;

    protected static string $resource = CustodyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // **The ledger panel, on the screen where the edit happens.** The factory has existed
            // since CHANGE-IMPACT-PLAN §6.1 and was mounted on five LIST tables only — which is
            // where you audit, not where you act. An operator about to retype a figure could not
            // see what the document had already done to the books without leaving the page.
            LedgerEntryAction::make(),
            DeleteAction::make()->visible(fn () => CustodyResource::canDelete($this->getRecord())),
        ];
    }
}
