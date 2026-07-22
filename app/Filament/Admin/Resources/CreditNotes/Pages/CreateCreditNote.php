<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\Lease;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCreditNote extends CreateRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The note's property is derived from its lease; a standalone tenant-level
        // note has no lease. When a lease IS chosen, re-validate its property against
        // the user's visible set so a tampered lease_id can't credit another
        // property's books (property isolation).
        if (! empty($data['lease_id'])) {
            CreditNoteResource::assertAssetInScope(
                Lease::with('unit')->find($data['lease_id'])?->unit?->asset_id
            );
        }

        // Creating a note straight to a posting status (bypassing the Issue action) still posts to
        // the GL dated issue_date — refuse a closed period here too, mirroring issue(). Catch the
        // guard so a closed period is a clean field-level toast, not an uncaught Livewire 500.
        if (in_array($data['status'] ?? 'draft', ['issued', 'applied'], true)) {
            try {
                \App\Support\PostingDate::assertOpen($data['issue_date'] ?? null, __('admin.fields.issue_date'));
            } catch (\DomainException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
                $this->halt();
            }
        }

        $data['issued_by_user_id'] = auth()->id();
        return $data;
    }

    protected function afterCreate(): void
    {
        // The header money fields are client-supplied (readOnly, not disabled). Re-derive them from
        // the now-persisted line items so a tampered submit can't post a fabricated total to the GL.
        $this->record->recomputeFromItems();
        $this->record->saveQuietly(); // the create's afterCommit ledger sync reads this corrected total
    }
}
