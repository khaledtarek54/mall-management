<?php

namespace App\Filament\Portal\Resources\MarketingPosts\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketingPosts extends ListRecords
{
    protected static string $resource = MarketingPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make()
                ->label(__('admin.marketing_posts.portal.compose'))
                ->visible(fn () => MarketingPostResource::canCreate()),
        ];
    }
}
