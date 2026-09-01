<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\PostingDate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustody extends EditRecord
{
    use AnnouncesLedgerRestatement;

    protected static string $resource = CustodyResource::class;

    /**
     * A custody date may not be moved into a CLOSED accounting period.
     *
     * `Custody` declares `#[PostingDateGuardedBy(GrantCustodyService::class)]`, and that service
     * does assert it — but the EDIT form reaches the same column with no guard at all. An
     * un-settled عهدة could therefore be back-dated into a closed month: the row saves, the
     * operator reads "Saved", and the GL re-post is refused inside the best-effort sync that only
     * logs. That is the exact divergence `App\Support\PostingDate` exists to prevent, and the
     * reason the posting-date register demands a guard per SOURCE rather than per service.
     *
     * A MISSING period is still allowed; only a closed one is refused.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        PostingDate::assertOpen($data['custody_date'] ?? null, __('admin.fields.custody_date'));

        return $data;
    }

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
