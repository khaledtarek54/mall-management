<?php

namespace App\Filament\Portal\Resources\MarketingPosts\Pages;

use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Services\MarketingPost\SubmitMarketingPostService;
use App\Support\Portal;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketingPost extends CreateRecord
{
    protected static string $resource = MarketingPostResource::class;

    /**
     * Stamp the retailer server-side and re-check the chosen mall.
     *
     * `tenant_id` is never taken from the form — it is the signed-in tenant, full stop. The
     * property Select's `options()` scope the RENDERING; Livewire state is attacker-controlled, so
     * the submitted `asset_id` goes through the same guard the API and the submit transition use.
     *
     * `created_by` stays null deliberately: that is what marks the post retailer-authored, so the
     * mall's verdict comes back to them (see MarketingPost::isTenantAuthored()). `status` is not a
     * form field at all — a new post is a draft, and Submit is the only way out of it.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenantId = Portal::tenantId();

        app(SubmitMarketingPostService::class)
            ->assertTenantTradesIn((int) $tenantId, (int) ($data['asset_id'] ?? 0));

        $data['tenant_id'] = $tenantId;
        $data['created_by'] = null;
        $data['status'] = MarketingPost::STATUS_DRAFT;

        return $data;
    }
}
