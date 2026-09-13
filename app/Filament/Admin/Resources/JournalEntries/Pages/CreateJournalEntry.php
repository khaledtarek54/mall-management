<?php

namespace App\Filament\Admin\Resources\JournalEntries\Pages;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use App\Support\PostingDate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the client-supplied property against the user's visible set —
        // the asset_id Select is enabled in All-Properties mode (property isolation).
        JournalEntryResource::assertAssetInScope($data['asset_id'] ?? null);

        // A date in a CLOSED month is refused at entry, on the field — the shape every other
        // money document's create page already has (bills, receipts, credit notes), and the one
        // the accountant's own document lacked (2026-09-13 audit): the draft saved cleanly and
        // was refused only at Post, after the lines were keyed, with nothing but reopening the
        // month able to fix it. The market refuses a closed post month at entry. A MISSING
        // period is allowed, as `PostingDate` says; an unbalanced draft is still allowed — a
        // parked entry may be finished later, and Post is where it must balance.
        try {
            PostingDate::assertOpen($data['entry_date'] ?? null, __('admin.fields.entry_date'));
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['data.entry_date' => $e->getMessage()]);
        }

        // The UI always creates a DRAFT; the accountant reviews then Posts it.
        // (is_manual is derived from the absent source link in the model.)
        $data['status'] = 'draft';

        return $data;
    }
}
