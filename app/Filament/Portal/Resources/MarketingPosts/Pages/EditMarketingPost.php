<?php

namespace App\Filament\Portal\Resources\MarketingPosts\Pages;

use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Services\MarketingPost\SubmitMarketingPostService;
use App\Support\Portal;
use Filament\Resources\Pages\EditRecord;

class EditMarketingPost extends EditRecord
{
    protected static string $resource = MarketingPostResource::class;

    /**
     * The same two guards as create, for the same reasons — an edit can move the post to another
     * mall, and Filament re-validates neither the tenant nor the property on update.
     *
     * `status` is pinned to what it already is rather than trusted from the payload: the form does
     * not render it, but a crafted Livewire update could still carry it, and `published` arriving
     * here would put an unreviewed offer in front of shoppers.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var MarketingPost $record */
        $record = $this->record;

        app(SubmitMarketingPostService::class)
            ->assertTenantTradesIn((int) Portal::tenantId(), (int) ($data['asset_id'] ?? $record->asset_id));

        $data['tenant_id'] = Portal::tenantId();
        $data['status'] = $record->status;

        return $data;
    }
}
