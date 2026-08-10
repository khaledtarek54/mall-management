<?php

namespace App\Filament\Admin\Resources\MarketingPosts\Pages;

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateMarketingPost extends CreateRecord
{
    protected static string $resource = MarketingPostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side — the Select is enabled in All-Properties
        // mode, so its value is client-supplied.
        MarketingPostResource::assertAssetInScope($data['asset_id'] ?? null);

        // Stamps the author AND marks the post operator-composed rather than retailer-submitted,
        // which is what stops an approval notification being sent to nobody. See
        // MarketingPost::isTenantAuthored().
        $data['created_by'] = Auth::id();

        return $data;
    }
}
