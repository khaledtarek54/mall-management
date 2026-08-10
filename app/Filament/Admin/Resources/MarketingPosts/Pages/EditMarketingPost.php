<?php

namespace App\Filament\Admin\Resources\MarketingPosts\Pages;

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketingPost extends EditRecord
{
    protected static string $resource = MarketingPostResource::class;

    /**
     * Filament stamps asset_id on create only, never on update — so an edit in All-Properties mode
     * could otherwise move a post into a mall the user cannot see. Re-validate on save too.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        MarketingPostResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /**
     * The model soft-deletes and the table ships a TrashedFilter, so the Edit page must expose
     * Delete / ForceDelete / Restore — otherwise the filter surfaces rows nothing can act on.
     * Delete stays super_admin-only via RoleGatedActions::canDelete.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
