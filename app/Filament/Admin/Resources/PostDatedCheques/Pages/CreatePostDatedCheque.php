<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Models\PostDatedCheque;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePostDatedCheque extends CreateRecord
{
    protected static string $resource = PostDatedChequeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The property is client-supplied — re-validate it against the user's scope.
        PostDatedChequeResource::assertAssetInScope($data['asset_id'] ?? null);
        $data['reference'] = PostDatedCheque::generateReference();

        // One physical cheque, one register row. The model refuses it either way — this only
        // turns the refusal into a toast over a still-filled form instead of a redirect that
        // loses what the operator typed. Same predicate, named once, so they cannot drift.
        // NOT a Filament `unique()` rule: keyed on the client-supplied tenant_id it would be an
        // existence oracle over tenants the user cannot see (UniqueRuleScopeConformanceTest).
        try {
            (new PostDatedCheque)->forceFill($data)->assertChequeNumberNotAlreadyLodged();
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }

        return $data;
    }
}
