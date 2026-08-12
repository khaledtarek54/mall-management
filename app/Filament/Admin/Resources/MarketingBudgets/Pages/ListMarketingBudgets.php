<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketingBudgets extends ListRecords
{
    protected static string $resource = MarketingBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()), CreateAction::make()];
    }
}
