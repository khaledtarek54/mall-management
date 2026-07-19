<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Models\PostDatedCheque;
use Filament\Resources\Pages\CreateRecord;

class CreatePostDatedCheque extends CreateRecord
{
    protected static string $resource = PostDatedChequeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The property is client-supplied — re-validate it against the user's scope.
        PostDatedChequeResource::assertAssetInScope($data['asset_id'] ?? null);
        $data['reference'] = PostDatedCheque::generateReference();

        return $data;
    }
}
