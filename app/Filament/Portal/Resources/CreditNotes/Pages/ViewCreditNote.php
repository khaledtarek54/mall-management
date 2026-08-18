<?php

namespace App\Filament\Portal\Resources\CreditNotes\Pages;

use App\Filament\Portal\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only, and with no header actions at all.
 *
 * The admin side offers apply / un-apply / void here; none of them belong to the tenant, and
 * `RefusesDeletionOfCommittedRecords` plus `CreditNoteService` are what govern them. An empty
 * action bar is the correct answer rather than an oversight.
 */
class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;
}
