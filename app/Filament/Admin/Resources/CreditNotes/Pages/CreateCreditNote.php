<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\Lease;
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

        $data['issued_by_user_id'] = auth()->id();
        return $data;
    }
}
