<?php

namespace App\Filament\Admin\Resources\Custodies\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReverseDocumentAction;
use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\Custody;
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
            // A float recorded in error. Distinct from SETTLING it, which is the normal end of a
            // عهدة and returns the unspent balance; this says the grant should not have happened.
            // Refused once anything has been spent against it — those transactions are their own GL
            // sources and reversing the float underneath them would strand their entries.
            //
            // Moved off `CustodiesTable`, where `RowActionPolicy` could not see it: a factory's
            // `->action()` lives in its own file, so that table reported ZERO write verbs while
            // carrying the reversal of a posted GL document. Safe to move, measured rather than
            // assumed — it gates on `CustodyResource::canEdit()`, which is exactly what reaching
            // this page requires, so no role loses the act.
            ReverseDocumentAction::make(
                can: fn (Custody $record) => CustodyResource::canEdit($record),
                label: 'admin.actions.reverse_custody',
                confirm: 'admin.actions.reverse_custody_confirm',
                done: 'admin.notifications.custody_reversed',
                when: fn (Custody $record) => ! $record->transactions()->exists(),
            ),
            DeleteAction::make()->visible(fn () => CustodyResource::canDelete($this->getRecord())),
        ];
    }
}
